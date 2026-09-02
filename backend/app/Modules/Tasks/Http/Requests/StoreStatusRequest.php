<?php

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Enums\TaskStatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'category' => ['required', Rule::in(TaskStatusCategory::values())],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
