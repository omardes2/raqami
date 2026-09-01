<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;

/**
 * Recomputes a daily attendance_record from its sessions — the single place daily
 * totals are derived (never in controllers). Sums per-session minutes, sets the
 * first check-in / last check-out, and derives the day status. Bumps `version`
 * on every write so a pending correction can detect the record changed.
 *
 * Each SESSION is already calculated server-side against its own segment; this
 * only aggregates. It never invents an absence — the materializer owns that.
 */
class AttendanceRecordAggregator
{
    public function aggregate(AttendanceRecord $record): AttendanceRecord
    {
        $sessions = $record->sessions()->orderBy('check_in_at')->get();

        if ($sessions->isEmpty()) {
            return $record;
        }

        $hasOpen = $sessions->contains(fn ($s) => $s->check_out_at === null);
        $first = $sessions->first();
        $lastClosed = $sessions->filter(fn ($s) => $s->check_out_at !== null)->sortByDesc('check_out_at')->first();

        $late = (int) $sessions->sum('late_minutes');

        $record->fill([
            'check_in_at' => $first->check_in_at,
            'check_out_at' => $hasOpen ? null : $lastClosed?->check_out_at,
            'worked_minutes' => (int) $sessions->sum('worked_minutes'),
            'break_minutes' => (int) $sessions->sum('break_minutes'),
            'late_minutes' => $late,
            'early_leave_minutes' => (int) $sessions->sum('early_leave_minutes'),
            'overtime_minutes' => (int) $sessions->sum('overtime_minutes'),
            'status' => $this->status($hasOpen, $late, $record),
            // A real punch always overrides a previously-materialized derived state.
            'is_materialized' => false,
            'materialized_at' => null,
            'is_manual' => $sessions->contains(fn ($s) => $s->is_manual),
            // First check-in / last check-out geo summary.
            'check_in_latitude' => $first->check_in_latitude,
            'check_in_longitude' => $first->check_in_longitude,
            'check_in_inside_geofence' => $first->check_in_inside_geofence,
            'check_in_location_id' => $first->check_in_location_id,
            'check_out_latitude' => $lastClosed?->check_out_latitude,
            'check_out_longitude' => $lastClosed?->check_out_longitude,
            'check_out_inside_geofence' => $lastClosed?->check_out_inside_geofence,
            'check_out_location_id' => $lastClosed?->check_out_location_id,
            'version' => (int) $record->version + 1,
        ]);
        $record->save();

        return $record;
    }

    private function status(bool $hasOpen, int $late, AttendanceRecord $record): AttendanceStatus
    {
        // A real punch always overrides a previously-materialized derived state.
        if ($late > 0) {
            return AttendanceStatus::Late;
        }

        return AttendanceStatus::Present;
    }
}
