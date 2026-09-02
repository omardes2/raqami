<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskRankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_id' => ['required', 'string'],
            'after_task_id' => ['nullable', 'string'],
            'before_task_id' => ['nullable', 'string'],
            'expected_version' => ['sometimes', 'integer'],
        ];
    }
}
