<?php

namespace App\Modules\Employees\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for the organization turnover report. Bounds the window to one
 * year (366 days) so an unbounded multi-year scan is impossible, and guarantees
 * well-formed ISO dates (malformed values return a localized 422, not a DB 500).
 */
class OrganizationTurnoverRequest extends FormRequest
{
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
                    $v->errors()->add('to', __('reports.date_range_too_large'));
                }
            }
        });
    }

    /**
     * Resolved [from, to] window. Defaults to the last 12 months ending today,
     * interpreted in the tenant's payroll/company timezone where provided.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    public function window(string $timezone): array
    {
        $to = $this->filled('to')
            ? CarbonImmutable::parse($this->query('to'), $timezone)
            : CarbonImmutable::now($timezone);
        $from = $this->filled('from')
            ? CarbonImmutable::parse($this->query('from'), $timezone)
            : $to->subYear();

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
