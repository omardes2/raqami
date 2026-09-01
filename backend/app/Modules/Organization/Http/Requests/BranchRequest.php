<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces permission
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $id = $this->route('branch')?->id ?? $this->route('branch');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('branches', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_headquarters' => ['sometimes', 'boolean'],
        ];
    }
}
