<?php

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\LeaveTypeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:64'],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::in(LeaveTypeCategory::values())],
            'paid_classification' => ['nullable', Rule::in(['paid', 'unpaid'])],
            'requires_attachment' => ['sometimes', 'boolean'],
            'attachment_required_after_minutes' => ['nullable', 'integer', 'min:0'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'allow_hourly' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }
}
