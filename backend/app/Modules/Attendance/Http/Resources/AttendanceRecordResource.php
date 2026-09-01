<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Attendance record representation. Precise GPS coordinates are SENSITIVE: they
 * are exposed only to the employee viewing their OWN record, or to a user holding
 * attendance.view_location. Everyone else sees the derived inside/outside flag
 * but never the raw coordinates (CLAUDE.md rules 5 & 14).
 *
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'work_schedule_id' => $this->work_schedule_id,
            'work_date' => $this->work_date?->toDateString(),
            'timezone' => $this->timezone,
            'scheduled_start_at' => $this->scheduled_start_at?->toISOString(),
            'scheduled_end_at' => $this->scheduled_end_at?->toISOString(),
            'check_in_at' => $this->check_in_at?->toISOString(),
            'check_out_at' => $this->check_out_at?->toISOString(),
            'worked_minutes' => $this->worked_minutes,
            'break_minutes' => $this->break_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'grace_minutes' => $this->grace_minutes,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'is_manual' => $this->is_manual,
            'corrected_at' => $this->corrected_at?->toISOString(),
            'check_in_inside_geofence' => $this->check_in_inside_geofence,
            'check_out_inside_geofence' => $this->check_out_inside_geofence,
        ];

        if ($this->canViewLocation($request)) {
            $data['location'] = [
                'check_in_latitude' => $this->check_in_latitude,
                'check_in_longitude' => $this->check_in_longitude,
                'check_in_location_id' => $this->check_in_location_id,
                'check_out_latitude' => $this->check_out_latitude,
                'check_out_longitude' => $this->check_out_longitude,
                'check_out_location_id' => $this->check_out_location_id,
            ];
        }

        return $data;
    }

    private function canViewLocation(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        // Own record → the employee may see their own coordinates.
        if ($this->employee !== null && $this->employee->user_id === $user->getKey()) {
            return true;
        }

        // Otherwise require attendance.view_location within a scope that covers
        // THIS employee (scope-aware, never leaks another scope's GPS — NB-1).
        return $this->employee !== null
            && app(EmployeeScopeResolver::class)->canAccess($user, $this->employee, 'attendance.view_location');
    }
}
