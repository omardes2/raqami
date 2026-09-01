<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\ScheduleScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in(ScheduleScopeType::values())],
            'scope_id' => ['nullable', 'string', 'size:26'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
        ];
    }
}
