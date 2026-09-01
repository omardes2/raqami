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

            'days' => [$creating ? 'required' : 'sometimes', 'array', 'max:7'],
            'days.*.weekday' => ['required_with:days', 'integer', 'between:0,6'],
            'days.*.is_working_day' => ['sometimes', 'boolean'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
