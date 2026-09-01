<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Support\EmployeeEnums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employment_status' => ['required', Rule::in(EmployeeEnums::EMPLOYMENT_STATUSES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
