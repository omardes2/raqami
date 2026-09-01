<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorized manual attendance entry. A trusted actor (HR/manager) supplies the
 * instants explicitly; the server still computes all minutes and status.
 */
class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'size:26'],
            'check_in_at' => ['required', 'date'],
            'check_out_at' => ['nullable', 'date', 'after:check_in_at'],
            // A manual entry bypasses the employee's own punch, so it must always
            // record WHY (audited). Required, non-empty, bounded.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
