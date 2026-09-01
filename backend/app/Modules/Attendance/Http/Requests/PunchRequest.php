<?php

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A check-in / check-out payload. The client sends ONLY facts — coordinates,
 * accuracy, an optional idempotency key, opaque device metadata. It never sends
 * times, statuses, or "inside geofence"; the server derives all of those.
 */
class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'client_request_id' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
