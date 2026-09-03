<?php

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(PayrollComponentType::values())],
            'calculation_mode' => ['required', Rule::in(PayrollComponentMode::values())],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
