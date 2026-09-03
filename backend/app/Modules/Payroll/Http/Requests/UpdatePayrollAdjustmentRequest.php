<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of a manual adjustment's business fields. Period and employee are
 * NOT reassignable through the API (enforced in the service and by DB trigger).
 */
class UpdatePayrollAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_visible_label' => ['sometimes', 'required', 'string', 'max:255'],
            'direction' => ['sometimes', 'required', 'string', Rule::in(PayrollLineDirection::values())],
            'amount_minor' => ['sometimes', 'required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in((array) config('billing.currencies', []))],
            'internal_reason' => ['sometimes', 'required', 'string', 'max:1000'],
            'source_payroll_entry_id' => ['nullable', 'string', 'ulid'],
        ];
    }
}
