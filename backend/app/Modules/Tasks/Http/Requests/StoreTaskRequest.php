<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\DueType;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            'parent_task_id' => ['nullable', 'string'],
            'status_id' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(TaskPriority::values())],
            'scope_type' => ['nullable', 'required_without:project_id', Rule::in(ScopeType::values())],
            'scope_id' => ['nullable', 'string'],
            'due_type' => ['sometimes', Rule::in(DueType::values())],
            'due_on' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'due_timezone' => ['nullable', 'string', 'timezone'],
            'start_on' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
            'client_request_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
