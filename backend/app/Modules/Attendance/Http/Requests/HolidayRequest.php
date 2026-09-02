<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\HolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:date'],
            'type' => ['sometimes', Rule::in(HolidayType::values())],
            'is_paid' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
