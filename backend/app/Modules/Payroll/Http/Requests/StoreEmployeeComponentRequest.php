<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payroll_component_id' => ['required', 'string', 'exists:payroll_components,id'],
            'fixed_amount_minor' => ['nullable', 'integer', 'min:0'],
            'rate_bps' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in((array) config('billing.currencies', []))],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
