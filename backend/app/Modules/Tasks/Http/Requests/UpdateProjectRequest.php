<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\ProjectStatus;
use App\Modules\Tasks\Enums\ProjectVisibility;
use App\Modules\Tasks\Enums\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(ProjectStatus::values())],
            'visibility' => ['sometimes', Rule::in(ProjectVisibility::values())],
            'scope_type' => ['sometimes', Rule::in(ScopeType::values())],
            'scope_id' => ['sometimes', 'nullable', 'string'],
            'owner_employee_id' => ['sometimes', 'nullable', 'string'],
            'start_on' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'expected_version' => ['sometimes', 'integer'],
        ];
    }
}
