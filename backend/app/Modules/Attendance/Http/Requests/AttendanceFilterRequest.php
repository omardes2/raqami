<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\AttendanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-parameter validation for attendance record/report/history listings.
 * Guarantees well-formed ISO dates and a sane ordering/range so a malformed
 * value returns a localized 422 instead of a raw DB date-parse error (500).
 */
class AttendanceFilterRequest extends FormRequest
{
    /** Upper bound on a single query window (days) to avoid unbounded scans. */
    private const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'status' => ['sometimes', 'nullable', Rule::in(AttendanceStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $from = $this->query('from');
            $to = $this->query('to');
            if ($from && $to && ! $v->errors()->hasAny(['from', 'to'])) {
                $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));
                if ($days > self::MAX_RANGE_DAYS) {
                    $v->errors()->add('to', __('attendance.date_range_too_large'));
                }
            }
        });
    }

    /** Only the filter keys the report services understand. */
    public function filters(): array
    {
        return [
            'from' => $this->query('from'),
            'to' => $this->query('to'),
            'employee_id' => $this->query('employee_id'),
            'status' => $this->query('status'),
        ];
    }
}
