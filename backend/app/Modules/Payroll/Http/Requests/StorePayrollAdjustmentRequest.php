<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual adjustment: a labelled, positive, single-direction amount in a valid
 * currency with a MANDATORY internal reason, targeting one employee of the period.
 * Optional source_payroll_entry_id is traceability to a prior finalized entry.
 */
class StorePayrollAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'ulid'],
            'employee_visible_label' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'string', Rule::in(PayrollLineDirection::values())],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', Rule::in((array) config('billing.currencies', []))],
            'internal_reason' => ['required', 'string', 'max:1000'],
            'source_payroll_entry_id' => ['nullable', 'string', 'ulid'],
        ];
    }
}
