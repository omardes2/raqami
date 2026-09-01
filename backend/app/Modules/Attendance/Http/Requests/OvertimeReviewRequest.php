<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OvertimeReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'allow_override' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'expected_record_version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
