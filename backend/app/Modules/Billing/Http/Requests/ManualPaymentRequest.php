<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // platform guard enforces access
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string'],
            'invoice_id' => ['required', 'string'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(config('billing.currencies'))],
            'method' => ['required', Rule::in(['cash', 'manual'])],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
