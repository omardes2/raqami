<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\AnomalyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnomalyReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                AnomalyStatus::Acknowledged->value,
                AnomalyStatus::Resolved->value,
                AnomalyStatus::Dismissed->value,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
