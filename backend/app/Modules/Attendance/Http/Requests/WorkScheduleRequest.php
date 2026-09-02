<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\WorkScheduleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
            'status' => ['sometimes', Rule::in(WorkScheduleStatus::values())],
            'description' => ['nullable', 'string', 'max:500'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'overtime_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],

            // Sprint 4: rotation (cyclic schedules). weekday is reinterpreted as
            // the cycle-day-index when cycle_length_days is set.
            'cycle_length_days' => ['nullable', 'integer', 'min:2', 'max:366'],
            'anchor_date' => ['nullable', 'date', 'required_with:cycle_length_days'],

            'days' => [$creating ? 'required' : 'sometimes', 'array', 'max:366'],
            'days.*.weekday' => ['required_with:days', 'integer', 'between:0,365'],
            'days.*.is_working_day' => ['sometimes', 'boolean'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],

            // Sprint 4: split-shift segments (each day may carry several windows).
            'days.*.segments' => ['sometimes', 'array', 'max:12'],
            'days.*.segments.*.start_time' => ['required_with:days.*.segments', 'date_format:H:i'],
            'days.*.segments.*.end_time' => ['required_with:days.*.segments', 'date_format:H:i'],
            'days.*.segments.*.sequence' => ['nullable', 'integer', 'min:1', 'max:12'],
            'days.*.segments.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.segments.*.grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.segments.*.overtime_after_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
