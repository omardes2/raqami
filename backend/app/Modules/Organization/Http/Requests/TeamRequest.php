<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $id = $this->route('team')?->id ?? $this->route('team');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('teams', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
            'department_id' => ['sometimes', 'nullable', 'string'],
            'team_lead_employee_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
