<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\DueType;
use App\Modules\Tasks\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::in(TaskPriority::values())],
            'due_type' => ['sometimes', Rule::in(DueType::values())],
            'due_on' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'due_timezone' => ['nullable', 'string', 'timezone'],
            'start_on' => ['sometimes', 'nullable', 'date'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'expected_version' => ['sometimes', 'integer'],
        ];
    }
}
