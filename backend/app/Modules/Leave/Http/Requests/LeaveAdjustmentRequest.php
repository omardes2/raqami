<?php

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'leave_type_id' => ['required', 'string'],
            'minutes' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'effective_date' => ['nullable', 'date'],
            'allow_negative_override' => ['sometimes', 'boolean'],
        ];
    }
}
