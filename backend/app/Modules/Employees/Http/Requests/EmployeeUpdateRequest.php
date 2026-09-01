<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Support\EmployeeEnums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Profile/employment fields only. Organizational placement (branch/department/
// job title/manager/teams) is changed via the transfer endpoint.
class EmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employment_type' => ['sometimes', Rule::in(EmployeeEnums::EMPLOYMENT_TYPES)],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'probation_end_date' => ['sometimes', 'nullable', 'date'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nationality' => ['sometimes', 'nullable', 'string', 'size:2'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
