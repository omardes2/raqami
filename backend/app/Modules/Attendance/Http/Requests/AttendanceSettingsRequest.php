<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
            'default_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'geofence_required' => ['sometimes', 'boolean'],
            'require_gps' => ['sometimes', 'boolean'],
            'min_gps_accuracy_meters' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'allow_early_check_in' => ['sometimes', 'boolean'],
            'early_check_in_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'allow_late_check_in' => ['sometimes', 'boolean'],
            'overtime_tracking_enabled' => ['sometimes', 'boolean'],
            'overtime_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'attendance_correction_enabled' => ['sometimes', 'boolean'],
            'allow_employee_correction_request' => ['sometimes', 'boolean'],
            'allow_unscheduled_work' => ['sometimes', 'boolean'],
        ];
    }
}
