<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual adjustment: a labelled, positive, single-direction amount in a valid
 * currency with a MANDATORY reason. Amounts are non-negative magnitudes; the sign
 * is the direction.
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
            'label' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'string', Rule::in(PayrollLineDirection::values())],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', Rule::in((array) config('billing.currencies', []))],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
