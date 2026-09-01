<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $id = $this->route('department')?->id ?? $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('departments', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'parent_department_id' => ['sometimes', 'nullable', 'string'],
            'manager_employee_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
