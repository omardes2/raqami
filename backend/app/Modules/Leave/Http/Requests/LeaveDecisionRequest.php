<?php

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
