<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\AttendanceMode;
use App\Modules\Attendance\Enums\ExceptionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:26'],
            'type' => ['required', Rule::in(ExceptionType::values())],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'attendance_mode' => ['nullable', Rule::in(AttendanceMode::values())],
            'alternate_schedule_id' => ['nullable', 'string', 'max:26'],
            'alternate_location_id' => ['nullable', 'string', 'max:26'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
