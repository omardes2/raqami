<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\ApprovalFlow;
use App\Modules\Leave\Enums\PeriodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_period_basis' => ['sometimes', Rule::in(PeriodType::values())],
            'leave_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'leave_year_start_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'default_approval_flow' => ['sometimes', Rule::in(ApprovalFlow::values())],
            'allow_withdrawal' => ['sometimes', 'boolean'],
            'allow_cancellation_request' => ['sometimes', 'boolean'],
            'display_day_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
