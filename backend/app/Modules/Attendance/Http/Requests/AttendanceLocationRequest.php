<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'branch_id' => ['nullable', 'string', 'size:26'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'latitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'longitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => [$creating ? 'required' : 'sometimes', 'integer', 'min:10', 'max:100000'],
            'require_accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
