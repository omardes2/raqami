<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Derives the daily attendance state for employees who did NOT produce it by
 * punching: weekend/off days, holidays, and absences on scheduled working days.
 * A REAL punch is never overwritten — a materialized record is only created when
 * none exists, or updated when the existing one is itself derived (is_materialized).
 *
 * Rules the SERVER owns here:
 *  - Absence is declared only AFTER the cutoff (scheduled start + configured
 *    minutes), never at midnight — an employee who has not yet arrived is not
 *    retroactively absent for a day still in progress.
 *  - A holiday overrides a scheduled working day: no absence is created on a
 *    holiday.
 *  - A real record left open past the day end is marked Incomplete (its punch
 *    data is preserved; only the derived status changes).
 *  - Idempotent: re-running yields the same result (derived rows are updated in
 *    place, real rows are left alone).
 *
 * Runs inside an already-scoped tenant context (RLS applies).
 */
class AttendanceDayMaterializer
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly HolidayResolver $holidays,
        private readonly AttendanceSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Materialize a single local date across all eligible employees.
     *
     * @return array{absent:int, weekend:int, holiday:int, incomplete:int, skipped:int}
     */
    public function materialize(CarbonImmutable $localDate, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $settings = $this->settings->current();

        $counts = ['absent' => 0, 'weekend' => 0, 'holiday' => 0, 'incomplete' => 0, 'skipped' => 0];

        if (! $settings->materialization_enabled) {
            return $counts;
        }

        Employee::query()
            ->whereIn('employment_status', AttendanceEligibility::ELIGIBLE_STATUSES)
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($localDate, $now, $settings, &$counts) {
                foreach ($employees as $employee) {
                    $outcome = $this->materializeEmployee($employee, $localDate, $now, $settings);
                    $counts[$outcome]++;
                }
            });

        return $counts;
    }

    /**
     * @return 'absent'|'weekend'|'holiday'|'incomplete'|'skipped'
     */
    public function materializeEmployee(Employee $employee, CarbonImmutable $localDate, CarbonImmutable $now, $settings): string
    {
        // Resolve the work day at local noon so overnight reach-back never
        // reattributes this to the previous day.
        $noonUtc = CarbonImmutable::parse($localDate->toDateString().' 12:00:00', $settings->default_timezone)->utc();
        $resolved = $this->resolver->resolveWorkDay($employee, $noonUtc, $settings->default_timezone);
        $workDate = $resolved->workDate->toDateString();

        return DB::transaction(function () use ($employee, $resolved, $workDate, $now, $settings) {
            // Serialize with concurrent materializer workers AND live punches for
            // this employee (same advisory key as check-in), so two runs converge
            // on one record instead of racing into a unique-constraint error.
            AttendanceLock::forEmployee((string) $employee->tenant_id, (string) $employee->getKey());

            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            // A real (punched) record: never overwrite it. Only flag a missing
            // checkout as Incomplete once the day has ended.
            if ($record !== null && ! $record->is_materialized) {
                return $this->reconcileRealRecord($record, $resolved, $now);
            }

            $holiday = $this->holidays->resolve($employee->branch_id, $resolved->workDate);

            // Nothing to materialize for an unscheduled employee on an ordinary day.
            if ($holiday === null && ! $resolved->hasSchedule()) {
                return 'skipped';
            }

            [$status, $holidayId] = $this->deriveState($resolved, $holiday, $now, $settings);

            if ($status === null) {
                return 'skipped'; // working day, before the absence cutoff
            }

            $this->writeMaterialized($record, $employee, $resolved, $status, $holidayId, $now);

            return $status->value === 'weekend' ? 'weekend'
                : ($status->value === 'holiday' ? 'holiday' : 'absent');
        });
    }

    /**
     * Decide the derived status (or null = not yet). Holiday first (a holiday is
     * never an absence), then off/weekend, then absence after the cutoff.
     *
     * @return array{0:?AttendanceStatus, 1:?string}
     */
    private function deriveState(ResolvedWorkDay $resolved, $holiday, CarbonImmutable $now, $settings): array
    {
        if ($holiday !== null) {
            return [AttendanceStatus::Holiday, (string) $holiday->getKey()];
        }

        if (! $resolved->isScheduledWorkingDay()) {
            return [AttendanceStatus::Weekend, null];
        }

        $cutoff = $resolved->scheduledStartAt->addMinutes((int) $settings->absence_materialize_after_minutes);
        if ($now->lessThan($cutoff)) {
            return [null, null];
        }

        return [AttendanceStatus::Absent, null];
    }

    /** A real record with an open session past the day end becomes Incomplete. */
    private function reconcileRealRecord(AttendanceRecord $record, ResolvedWorkDay $resolved, CarbonImmutable $now): string
    {
        $hasOpen = $record->sessions()->whereNull('check_out_at')->exists();
        $dayEnd = $this->dayEnd($resolved);

        if ($hasOpen && $dayEnd !== null && $now->greaterThan($dayEnd)
            && $record->status !== AttendanceStatus::Incomplete) {
            $record->fill([
                'status' => AttendanceStatus::Incomplete,
                'version' => (int) $record->version + 1,
            ])->save();

            return 'incomplete';
        }

        return 'skipped';
    }

    /** Create or update-in-place the derived (materialized) record. */
    private function writeMaterialized(?AttendanceRecord $record, Employee $employee, ResolvedWorkDay $resolved, AttendanceStatus $status, ?string $holidayId, CarbonImmutable $now): void
    {
        $attributes = [
            'work_schedule_id' => $resolved->schedule?->getKey(),
            'timezone' => $resolved->timezone,
            'scheduled_start_at' => $status === AttendanceStatus::Absent ? $resolved->scheduledStartAt : null,
            'scheduled_end_at' => $status === AttendanceStatus::Absent ? $this->dayEnd($resolved) : null,
            'grace_minutes' => $resolved->graceMinutes,
            'status' => $status,
            'holiday_id' => $holidayId,
            'is_materialized' => true,
            'materialized_at' => $now,
            'source' => AttendanceSource::System,
        ];

        if ($record !== null) {
            // Idempotent update of an already-derived record — no duplicate audit.
            $attributes['version'] = (int) $record->version + 1;
            $record->fill($attributes)->save();

            return;
        }

        $created = AttendanceRecord::query()->create(array_merge($attributes, [
            'employee_id' => $employee->getKey(),
            'work_date' => $resolved->workDate->toDateString(),
        ]));

        // Audit only the first time a day is derived (system actor).
        $this->audit->log('attendance.materialized', [
            'subject' => $created,
            'metadata' => [
                'employee_id' => (string) $employee->getKey(),
                'work_date' => $resolved->workDate->toDateString(),
                'status' => $status->value,
            ],
        ]);
    }

    private function dayEnd(ResolvedWorkDay $resolved): ?CarbonImmutable
    {
        $last = null;
        foreach ($resolved->segments as $segment) {
            if ($last === null || $segment->endAt->greaterThan($last)) {
                $last = $segment->endAt;
            }
        }

        return $last;
    }
}
