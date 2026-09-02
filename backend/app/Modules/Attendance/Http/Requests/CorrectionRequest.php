<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requested_check_in_at' => ['nullable', 'date'],
            'requested_check_out_at' => ['nullable', 'date'],
            // Required by the service on a multi-session day; optional otherwise.
            'attendance_session_id' => ['nullable', 'string', 'max:26'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
