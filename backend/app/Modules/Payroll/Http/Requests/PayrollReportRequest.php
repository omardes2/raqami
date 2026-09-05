<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for payroll report endpoints. Filters are narrowing only —
 * they never widen the company-wide authorization the controller enforces, and an
 * out-of-tenant id simply yields an empty result (RLS-scoped), never a leak.
 */
class PayrollReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payroll_period_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ];
    }

    /** @return array{payroll_period_id?:?string, employee_id?:?string, currency?:?string} */
    public function filters(): array
    {
        return [
            'payroll_period_id' => $this->query('payroll_period_id'),
            'employee_id' => $this->query('employee_id'),
            'currency' => $this->query('currency') ? strtoupper((string) $this->query('currency')) : null,
        ];
    }
}
