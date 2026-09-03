<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finalization body. A negative-net override reason is optional in general and
 * MANDATORY (enforced in the service, with the payroll.negative_override permission)
 * only when the run contains a negative-net entry.
 */
class FinalizePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'negative_net_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
