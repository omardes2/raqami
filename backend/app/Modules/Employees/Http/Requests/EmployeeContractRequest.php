<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Support\EmployeeEnums;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Contract foundation — NO compensation fields (ADR-014).
class EmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $id = $this->route('contract')?->id ?? $this->route('contract');

        return [
            'contract_number' => [
                'required', 'string', 'max:64',
                Rule::unique('employee_contracts', 'contract_number')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'contract_type' => ['sometimes', Rule::in(EmployeeEnums::CONTRACT_TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'probation_end_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(EmployeeEnums::CONTRACT_STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'document_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
