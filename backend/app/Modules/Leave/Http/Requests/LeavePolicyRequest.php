<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\AccrualFrequency;
use App\Modules\Leave\Enums\ApprovalFlow;
use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\EntitlementMethod;
use App\Modules\Leave\Enums\PeriodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'leave_type_id' => [$creating ? 'required' : 'prohibited', 'string'],
            'name' => [$required, 'string', 'max:255'],
            'effective_from' => [$required, 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'period_basis' => ['sometimes', Rule::in(PeriodType::values())],
            'entitlement_method' => ['sometimes', Rule::in(EntitlementMethod::values())],
            'entitlement_minutes' => ['sometimes', 'integer', 'min:0'],
            'accrual_frequency' => ['sometimes', Rule::in(AccrualFrequency::values())],
            'accrual_minutes' => ['sometimes', 'integer', 'min:0'],
            'proration_enabled' => ['sometimes', 'boolean'],
            'max_balance_minutes' => ['nullable', 'integer', 'min:0'],
            'allow_negative_balance' => ['sometimes', 'boolean'],
            'max_negative_minutes' => ['nullable', 'integer', 'min:0'],
            'carry_forward_enabled' => ['sometimes', 'boolean'],
            'carry_forward_max_minutes' => ['nullable', 'integer', 'min:0'],
            'carry_forward_expiry_days' => ['nullable', 'integer', 'min:0'],
            'consumption_basis' => ['sometimes', Rule::in(ConsumptionBasis::values())],
            'nominal_day_minutes' => ['nullable', 'integer', 'min:1'],
            'count_holidays' => ['sometimes', 'boolean'],
            'count_non_working_days' => ['sometimes', 'boolean'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'minimum_request_minutes' => ['nullable', 'integer', 'min:0'],
            'maximum_request_minutes' => ['nullable', 'integer', 'min:0'],
            'minimum_notice_days' => ['nullable', 'integer', 'min:0'],
            'maximum_advance_booking_days' => ['nullable', 'integer', 'min:0'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'allow_withdrawal' => ['sometimes', 'boolean'],
            'allow_cancellation_request' => ['sometimes', 'boolean'],
            'approval_flow' => ['sometimes', Rule::in(ApprovalFlow::values())],
        ];
    }
}
