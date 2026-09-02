<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
        ];
    }
}
