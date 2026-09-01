<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Support\EmployeeEnums;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'employee_number' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('employees', 'employee_number')->where('tenant_id', $tenantId),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'branch_id' => ['sometimes', 'nullable', 'string'],
            'department_id' => ['sometimes', 'nullable', 'string'],
            'job_title_id' => ['sometimes', 'nullable', 'string'],
            'direct_manager_employee_id' => ['sometimes', 'nullable', 'string'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['string'],

            'employment_status' => ['sometimes', Rule::in(EmployeeEnums::EMPLOYMENT_STATUSES)],
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
