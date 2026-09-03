<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payroll_timezone' => ['sometimes', 'timezone'],
            'overtime_pay_enabled' => ['sometimes', 'boolean'],
            'require_four_eyes' => ['sometimes', 'boolean'],
            'allow_self_payroll' => ['sometimes', 'boolean'],
        ];
    }
}
