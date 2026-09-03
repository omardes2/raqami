<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\TaskStatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'category' => ['sometimes', Rule::in(TaskStatusCategory::values())],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
