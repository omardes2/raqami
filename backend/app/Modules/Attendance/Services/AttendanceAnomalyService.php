<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AnomalyStatus;
use App\Modules\Attendance\Enums\AnomalyType;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceAnomaly;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Audit\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rule-based attendance anomaly detection. NO AI, NO fraud assertions — every
 * type uses neutral, descriptive language (e.g. "suspicious_location_change",
 * never "fraud"). Detection never takes disciplinary action; it only records a
 * finding for a human to review. Each rule is gated by a tenant-configurable
 * threshold (null = rule off) and every finding carries a stable dedupe_key so
 * re-running the detector never duplicates a finding (idempotent).
 *
 * Runs inside an already-scoped tenant context (RLS applies).
 */
class AttendanceAnomalyService
{
    public function __construct(
        private readonly AttendanceSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Detect anomalies for a local date across the tenant. Returns the number of
     * NEW findings created (existing findings are left untouched — idempotent).
     */
    public function detect(CarbonImmutable $localDate, ?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $settings = $this->settings->current();
        $created = 0;

        $records = AttendanceRecord::query()
            ->whereDate('work_date', $localDate->toDateString())
            ->with(['sessions', 'employee'])
            ->get();

        foreach ($records as $record) {
            $created += $this->recordRules($record, $settings, $now);
        }

        $created += $this->latenessStreakRule($localDate, $settings, $now);
        $created += $this->excessiveCorrectionsRule($settings, $now);

        return $created;
    }

    /** Per-record / per-session rules. */
    private function recordRules(AttendanceRecord $record, $settings, CarbonImmutable $now): int
    {
        $created = 0;
        $sessions = $record->sessions->sortBy('check_in_at')->values();

        // Missing checkout: an open session on a day that has already ended.
        $open = $sessions->firstWhere('check_out_at', null);
        if ($open !== null && $record->scheduled_end_at !== null
            && $now->greaterThan(CarbonImmutable::parse($record->scheduled_end_at))) {
            $created += $this->flag(
                AnomalyType::MissingCheckout, 'warning',
                'missing_checkout:'.$record->getKey(),
                $now, $record->employee_id, $record->getKey(), $open->getKey(),
                ['work_date' => $record->work_date->toDateString()],
            );
        }

        // Long session: a session longer than the configured maximum.
        $maxMinutes = $settings->anomaly_max_session_minutes;
        if ($maxMinutes !== null) {
            foreach ($sessions as $session) {
                $duration = $this->sessionMinutes($session, $now);
                if ($duration > (int) $maxMinutes) {
                    $created += $this->flag(
                        AnomalyType::LongSession, 'info',
                        'long_session:'.$session->getKey(),
                        $now, $record->employee_id, $record->getKey(), $session->getKey(),
                        ['minutes' => $duration, 'threshold' => (int) $maxMinutes],
                    );
                }
            }
        }

        // Overlapping sessions (should be prevented on write; detected defensively).
        $created += $this->overlapRule($record, $sessions, $now);

        // Suspicious location change: a large GPS jump between consecutive sessions.
        $jump = $settings->anomaly_gps_jump_meters;
        if ($jump !== null) {
            $created += $this->locationJumpRule($record, $sessions, (int) $jump, $now);
        }

        return $created;
    }

    /** @param Collection<int, AttendanceSession> $sessions */
    private function overlapRule(AttendanceRecord $record, $sessions, CarbonImmutable $now): int
    {
        $prevEnd = null;
        foreach ($sessions as $session) {
            $start = CarbonImmutable::parse($session->check_in_at);
            if ($prevEnd !== null && $start->lessThan($prevEnd)) {
                return $this->flag(
                    AnomalyType::OverlappingSessions, 'warning',
                    'overlapping_sessions:'.$record->getKey(),
                    $now, $record->employee_id, $record->getKey(), $session->getKey(),
                    ['work_date' => $record->work_date->toDateString()],
                );
            }
            $prevEnd = $session->check_out_at !== null ? CarbonImmutable::parse($session->check_out_at) : $prevEnd;
        }

        return 0;
    }

    /** @param Collection<int, AttendanceSession> $sessions */
    private function locationJumpRule(AttendanceRecord $record, $sessions, int $jumpMeters, CarbonImmutable $now): int
    {
        $created = 0;
        $prev = null;
        foreach ($sessions as $session) {
            if ($prev !== null
                && $prev->check_out_latitude !== null && $prev->check_out_longitude !== null
                && $session->check_in_latitude !== null && $session->check_in_longitude !== null) {
                $distance = $this->haversineMeters(
                    (float) $prev->check_out_latitude, (float) $prev->check_out_longitude,
                    (float) $session->check_in_latitude, (float) $session->check_in_longitude,
                );
                if ($distance > $jumpMeters) {
                    $created += $this->flag(
                        AnomalyType::SuspiciousLocationChange, 'info',
                        'suspicious_location_change:'.$session->getKey(),
                        $now, $record->employee_id, $record->getKey(), $session->getKey(),
                        ['distance_meters' => (int) round($distance), 'threshold' => $jumpMeters],
                    );
                }
            }
            $prev = $session;
        }

        return $created;
    }

    /** Consecutive scheduled late days ending on $localDate for an employee. */
    private function latenessStreakRule(CarbonImmutable $localDate, $settings, CarbonImmutable $now): int
    {
        $threshold = $settings->anomaly_lateness_streak_days;
        if ($threshold === null || (int) $threshold < 1) {
            return 0;
        }

        $created = 0;
        $from = $localDate->subDays((int) $threshold - 1)->toDateString();

        $byEmployee = AttendanceRecord::query()
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $localDate->toDateString())
            ->orderBy('work_date')
            ->get()
            ->groupBy('employee_id');

        foreach ($byEmployee as $employeeId => $records) {
            $lateDays = $records->where('status', AttendanceStatus::Late)->count();
            if ($lateDays >= (int) $threshold && $records->last()->status === AttendanceStatus::Late) {
                $created += $this->flag(
                    AnomalyType::LatenessStreak, 'info',
                    'lateness_streak:'.$employeeId.':'.$localDate->toDateString(),
                    $now, (string) $employeeId, null, null,
                    ['days' => $lateDays, 'threshold' => (int) $threshold],
                );
            }
        }

        return $created;
    }

    /** Employees with an unusually high number of corrections in a trailing window. */
    private function excessiveCorrectionsRule($settings, CarbonImmutable $now): int
    {
        $threshold = $settings->anomaly_corrections_threshold;
        if ($threshold === null || (int) $threshold < 1) {
            return 0;
        }

        $created = 0;
        $windowStart = $now->subDays(30);
        $period = $now->format('Y-m');

        $counts = AttendanceCorrection::query()
            ->where('created_at', '>=', $windowStart)
            ->get()
            ->groupBy('employee_id');

        foreach ($counts as $employeeId => $corrections) {
            if ($corrections->count() >= (int) $threshold) {
                $created += $this->flag(
                    AnomalyType::ExcessiveCorrections, 'info',
                    'excessive_corrections:'.$employeeId.':'.$period,
                    $now, (string) $employeeId, null, null,
                    ['count' => $corrections->count(), 'threshold' => (int) $threshold],
                );
            }
        }

        return $created;
    }

    /**
     * Idempotently record a finding. The (tenant_id, dedupe_key) unique index makes
     * a re-run a no-op; a resolved/dismissed finding is never silently reopened.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function flag(AnomalyType $type, string $severity, string $dedupeKey, CarbonImmutable $now, string $employeeId, ?string $recordId, ?string $sessionId, array $metadata): int
    {
        return DB::transaction(function () use ($type, $severity, $dedupeKey, $now, $employeeId, $recordId, $sessionId, $metadata) {
            $existing = AttendanceAnomaly::query()->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return 0;
            }

            $anomaly = AttendanceAnomaly::query()->create([
                'employee_id' => $employeeId,
                'attendance_record_id' => $recordId,
                'attendance_session_id' => $sessionId,
                'type' => $type->value,
                'severity' => $severity,
                'detected_at' => $now,
                'status' => AnomalyStatus::Open->value,
                'metadata' => $metadata ?: null,
                'dedupe_key' => $dedupeKey,
            ]);

            $this->audit->log('attendance.anomaly_detected', [
                'subject' => $anomaly,
                'metadata' => ['type' => $type->value, 'employee_id' => $employeeId],
            ]);

            return 1;
        });
    }

    /** Transition a finding to a closed state (resolved/dismissed) — human action. */
    public function resolve(AttendanceAnomaly $anomaly, Model $actor, AnomalyStatus $status, ?string $note = null): AttendanceAnomaly
    {
        if (! in_array($status, [AnomalyStatus::Resolved, AnomalyStatus::Dismissed, AnomalyStatus::Acknowledged], true)) {
            throw ValidationException::withMessages(['status' => [__('attendance.anomaly_reviewed')]]);
        }

        if ($anomaly->status->isClosed()) {
            throw ValidationException::withMessages(['status' => [__('attendance.anomaly_reviewed')]]);
        }

        return DB::transaction(function () use ($anomaly, $actor, $status, $note) {
            $anomaly->fill([
                'status' => $status->value,
                'resolved_at' => $status->isClosed() ? CarbonImmutable::now()->utc() : null,
                'resolved_by_user_id' => (string) $actor->getKey(),
                'resolution_note' => $note,
            ])->save();

            $this->audit->log('attendance.anomaly_reviewed', [
                'actor' => $actor,
                'subject' => $anomaly,
                'metadata' => ['status' => $status->value],
            ]);

            return $anomaly;
        });
    }

    private function sessionMinutes(AttendanceSession $session, CarbonImmutable $now): int
    {
        $start = CarbonImmutable::parse($session->check_in_at);
        $end = $session->check_out_at !== null ? CarbonImmutable::parse($session->check_out_at) : $now;

        return (int) floor($start->diffInSeconds($end, true) / 60);
    }

    /** Great-circle distance in meters. */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
