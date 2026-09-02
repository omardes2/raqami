<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\LeaveRequestKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string'],
            'request_kind' => ['sometimes', Rule::in(LeaveRequestKind::values())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
