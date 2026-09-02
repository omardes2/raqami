<?php

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeavePolicyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in(['company', 'branch', 'department', 'team', 'employee'])],
            'scope_id' => ['nullable', 'string', 'required_unless:scope_type,company'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['sometimes', 'integer'],
        ];
    }
}
