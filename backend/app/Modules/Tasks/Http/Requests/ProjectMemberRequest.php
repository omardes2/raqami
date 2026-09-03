<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\ProjectMembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'role' => ['required', Rule::in(ProjectMembershipRole::values())],
        ];
    }
}
