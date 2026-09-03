<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\ProjectVisibility;
use App\Modules\Tasks\Enums\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'visibility' => ['sometimes', Rule::in(ProjectVisibility::values())],
            'scope_type' => ['required', Rule::in(ScopeType::values())],
            'scope_id' => ['nullable', 'string', 'required_unless:scope_type,company'],
            'owner_employee_id' => ['nullable', 'string'],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
        ];
    }
}
