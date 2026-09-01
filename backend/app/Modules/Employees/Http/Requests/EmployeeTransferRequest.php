<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'department_id' => ['sometimes', 'nullable', 'string'],
            'job_title_id' => ['sometimes', 'nullable', 'string'],
            'direct_manager_employee_id' => ['sometimes', 'nullable', 'string'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['string'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
