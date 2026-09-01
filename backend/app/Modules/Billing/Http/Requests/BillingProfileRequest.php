<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'billing_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'preferred_currency' => ['sometimes', 'nullable', Rule::in(config('billing.currencies'))],
            'invoice_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
