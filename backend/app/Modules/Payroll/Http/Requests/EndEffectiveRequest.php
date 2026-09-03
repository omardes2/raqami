<?php

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared request for ending an effective-dated row (compensation / component). */
class EndEffectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date'],
        ];
    }
}
