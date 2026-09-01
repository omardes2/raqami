<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankTransferSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            // Invoice must belong to the active tenant.
            'invoice_id' => ['required', 'string', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(config('billing.currencies'))],
            'transfer_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
