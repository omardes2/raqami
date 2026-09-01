<?php

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JobTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $id = $this->route('jobTitle')?->id ?? $this->route('jobTitle');

        return [
            'title' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('job_titles', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'level' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
