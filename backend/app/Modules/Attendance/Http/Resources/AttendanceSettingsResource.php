<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceSetting */
class AttendanceSettingsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'default_timezone' => $this->default_timezone,
            'default_grace_minutes' => $this->default_grace_minutes,
            'geofence_required' => $this->geofence_required,
            'require_gps' => $this->require_gps,
            'min_gps_accuracy_meters' => $this->min_gps_accuracy_meters,
            'allow_early_check_in' => $this->allow_early_check_in,
            'early_check_in_window_minutes' => $this->early_check_in_window_minutes,
            'allow_late_check_in' => $this->allow_late_check_in,
            'overtime_tracking_enabled' => $this->overtime_tracking_enabled,
            'overtime_after_minutes' => $this->overtime_after_minutes,
            'attendance_correction_enabled' => $this->attendance_correction_enabled,
            'allow_employee_correction_request' => $this->allow_employee_correction_request,
            'allow_unscheduled_work' => $this->allow_unscheduled_work,
        ];
    }
}
