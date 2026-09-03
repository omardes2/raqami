<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Calculation\Input\AdjustmentItem;
use App\Modules\Payroll\Calculation\Input\BaseSegment;
use App\Modules\Payroll\Calculation\Input\CalculationInput;
use App\Modules\Payroll\Calculation\Input\ComponentSegment;
use App\Modules\Payroll\Calculation\Input\OvertimeItem;
use App\Modules\Payroll\Calculation\Input\UnpaidLeaveSegment;
use App\Modules\Payroll\Calculation\Resolvers\PayrollLeaveInputResolver;
use App\Modules\Payroll\Calculation\Resolvers\PayrollOvertimeInputResolver;
use App\Modules\Payroll\Calculation\Resolvers\PayrollScheduleInputResolver;
use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollErrorCode;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Models\EmployeeCompensationComponent;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Support\Money\CurrencyMetadata;
use Illuminate\Support\Collection;

/**
 * Assembles the authoritative, normalized calculation input for ONE employee from
 * the domain adapters + the effective-dated compensation/component rows, and builds
 * the canonical input snapshot. All controlled failures raise a
 * PayrollCalculationException with a bounded error code (never a partial result).
 * The FULL-month expected minutes are the proration denominator; employment,
 * compensation and leave only affect numerators/deductions.
 */
class PayrollInputBuilder
{
    public function __construct(
        private readonly PayrollScheduleInputResolver $schedule,
        private readonly PayrollLeaveInputResolver $leave,
        private readonly PayrollOvertimeInputResolver $overtime,
    ) {}

    public function build(Employee $employee, PayrollPeriod $period, PayrollSetting $settings, PayrollRun $run): PreparedCalculation
    {
        $periodStart = $period->period_start->toDateString();
        $periodEnd = $period->period_end->toDateString();
        $tz = $period->timezone;

        // Employment interval clipped to the period (inclusive).
        $hire = $employee->hire_date?->toDateString();
        $term = $employee->termination_date?->toDateString();
        $employStart = ($hire !== null && $hire > $periodStart) ? $hire : $periodStart;
        $employEnd = ($term !== null && $term < $periodEnd) ? $term : $periodEnd;

        // 1. Schedule — full-month expected minutes (denominator) + per-date facts.
        $resolution = $this->schedule->resolve($employee, $periodStart, $periodEnd, $tz);
        $days = $resolution['days'];
        $periodExpected = $resolution['period_expected_minutes'];
        if ($periodExpected <= 0) {
            throw new PayrollCalculationException(PayrollErrorCode::ZeroExpectedMinutes, ['period_start' => $periodStart]);
        }

        $expectedIntervalsByDate = [];
        foreach ($days as $d => $info) {
            $expectedIntervalsByDate[$d] = $info['intervals'];
        }

        // 2. Compensations overlapping the period.
        $comps = EmployeeCompensation::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $periodStart))
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        $employedExpected = $this->sumMinutes($days, $employStart, $employEnd);

        $currencies = [];
        $baseSegments = [];
        $compRowsForSnapshot = [];
        $coveredMinutes = 0;

        foreach ($comps as $comp) {
            $cFrom = $comp->effective_from->toDateString();
            $cTo = $comp->effective_to?->toDateString();
            $segFrom = $this->maxDate([$cFrom, $employStart, $periodStart]);
            $segTo = $this->minDate([$cTo ?? $periodEnd, $employEnd, $periodEnd]);
            if ($segFrom > $segTo) {
                continue; // no overlap with employment/period
            }

            $currencies[$comp->currency] = true;
            $payable = $this->sumMinutes($days, $segFrom, $segTo);
            $coveredMinutes += $payable;

            $baseSegments[] = new BaseSegment((string) $comp->getKey(), (int) $comp->base_amount_minor, $payable, $segFrom, $cTo);
            $compRowsForSnapshot[] = [
                'id' => (string) $comp->getKey(),
                'version' => (int) $comp->version,
                'currency' => $comp->currency,
                'base_amount_minor' => (int) $comp->base_amount_minor,
                'overtime_rate_minor_per_hour' => $comp->overtime_rate_minor_per_hour !== null ? (int) $comp->overtime_rate_minor_per_hour : null,
                'effective_from' => $cFrom,
                'effective_to' => $cTo,
            ];
        }

        if (count($currencies) > 1) {
            throw new PayrollCalculationException(PayrollErrorCode::CurrencyChangeInPeriod, ['currencies' => implode(',', array_keys($currencies))]);
        }
        // Some payable scheduled minutes inside employment are not covered by any comp.
        if ($coveredMinutes < $employedExpected) {
            throw new PayrollCalculationException(PayrollErrorCode::MissingCompensation, ['employee_id' => (string) $employee->getKey()]);
        }
        if ($baseSegments === []) {
            throw new PayrollCalculationException(PayrollErrorCode::MissingCompensation, ['employee_id' => (string) $employee->getKey()]);
        }

        $currency = CurrencyMetadata::normalize((string) array_key_first($currencies));

        // 3. Components (effective-dated). Fixed → per assignment; percent → per (assignment ∩ comp segment).
        $componentSegments = [];
        $componentRowsForSnapshot = [];
        $assignments = EmployeeCompensationComponent::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $periodStart))
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        $catalog = PayrollComponent::query()
            ->whereIn('id', $assignments->pluck('payroll_component_id')->unique()->all())
            ->get()->keyBy('id');

        foreach ($assignments as $a) {
            /** @var PayrollComponent|null $component */
            $component = $catalog->get($a->payroll_component_id);
            if ($component === null) {
                continue;
            }
            $aFrom = $a->effective_from->toDateString();
            $aTo = $a->effective_to?->toDateString();
            $code = $component->code;
            $label = $component->name;
            $type = $component->type;
            $mode = $component->calculation_mode;

            $componentRowsForSnapshot[] = [
                'assignment_id' => (string) $a->getKey(),
                'version' => (int) $a->version,
                'component_id' => (string) $component->getKey(),
                'code' => $code,
                'type' => $type->value,
                'mode' => $mode->value,
                'fixed_amount_minor' => $a->fixed_amount_minor !== null ? (int) $a->fixed_amount_minor : null,
                'rate_bps' => $a->rate_bps !== null ? (int) $a->rate_bps : null,
                'currency' => $a->currency,
                'effective_from' => $aFrom,
                'effective_to' => $aTo,
            ];

            if ($mode === PayrollComponentMode::Fixed) {
                if ($a->currency !== null && CurrencyMetadata::normalize($a->currency) !== $currency) {
                    throw new PayrollCalculationException(PayrollErrorCode::ComponentCurrencyMismatch, ['assignment_id' => (string) $a->getKey()]);
                }
                $segFrom = $this->maxDate([$aFrom, $employStart, $periodStart]);
                $segTo = $this->minDate([$aTo ?? $periodEnd, $employEnd, $periodEnd]);
                if ($segFrom > $segTo) {
                    continue;
                }
                $payable = $this->sumMinutes($days, $segFrom, $segTo);
                $componentSegments[] = new ComponentSegment(
                    (string) $a->getKey(), (string) $component->getKey(), $code, $label,
                    $type, $mode, $payable, 0, (int) $a->fixed_amount_minor, null, $segFrom, $aTo,
                );

                continue;
            }

            // percent_of_base: split across each compensation base segment.
            foreach ($baseSegments as $base) {
                $segFrom = $this->maxDate([$aFrom, $base->effectiveFrom, $employStart, $periodStart]);
                $segTo = $this->minDate([$aTo ?? $periodEnd, $base->effectiveTo ?? $periodEnd, $employEnd, $periodEnd]);
                if ($segFrom > $segTo) {
                    continue;
                }
                $payable = $this->sumMinutes($days, $segFrom, $segTo);
                $componentSegments[] = new ComponentSegment(
                    (string) $a->getKey(), (string) $component->getKey(), $code, $label,
                    $type, $mode, $payable, $base->monthlyBaseMinor, null, (int) $a->rate_bps, $segFrom, $aTo,
                );
            }
        }

        // 4. Leave — unpaid coverage within expected work, per request per comp segment.
        $leaveRecords = $this->leave->resolve($employee, $expectedIntervalsByDate);
        $leaveForSnapshot = [];
        $unpaidAcc = []; // key: leaveRequestId|baseIndex => [minutes, monthlyBase, from, to]
        foreach ($leaveRecords as $rec) {
            $leaveForSnapshot[] = [
                'leave_request_id' => $rec['leave_request_id'],
                'leave_type_id' => $rec['leave_type_id'],
                'classification' => $rec['classification'],
                'work_date' => $rec['work_date'],
                'coverage_minutes' => $rec['coverage_minutes'],
            ];
            if ($rec['coverage_minutes'] <= 0) {
                continue;
            }
            if ($rec['classification'] === null) {
                throw new PayrollCalculationException(PayrollErrorCode::UnclassifiedLeaveType, [
                    'leave_request_id' => $rec['leave_request_id'], 'work_date' => $rec['work_date'],
                ]);
            }
            if ($rec['classification'] !== 'unpaid') {
                continue; // paid leave → no deduction
            }
            $base = $this->baseSegmentForDate($baseSegments, $rec['work_date']);
            if ($base === null) {
                continue; // no covering compensation segment (non-employed day)
            }
            $key = $rec['leave_request_id'].'|'.$base->compensationId;
            if (! isset($unpaidAcc[$key])) {
                $unpaidAcc[$key] = ['request' => $rec['leave_request_id'], 'minutes' => 0, 'base' => $base->monthlyBaseMinor, 'from' => $rec['work_date'], 'to' => $rec['work_date']];
            }
            $unpaidAcc[$key]['minutes'] += $rec['coverage_minutes'];
            $unpaidAcc[$key]['from'] = min($unpaidAcc[$key]['from'], $rec['work_date']);
            $unpaidAcc[$key]['to'] = max($unpaidAcc[$key]['to'], $rec['work_date']);
        }
        $unpaidSegments = [];
        foreach ($unpaidAcc as $u) {
            $unpaidSegments[] = new UnpaidLeaveSegment($u['request'], $u['base'], $u['minutes'], $u['from'], $u['to']);
        }

        // 5. Overtime — approved minutes valued at the effective compensation OT rate.
        $overtimeEnabled = (bool) $settings->overtime_pay_enabled;
        $overtimeItems = [];
        $overtimeForSnapshot = [];
        foreach ($this->overtime->resolve($employee, $periodStart, $periodEnd) as $ot) {
            $overtimeForSnapshot[] = ['approval_id' => $ot['approval_id'], 'work_date' => $ot['work_date'], 'status' => 'approved', 'approved_minutes' => $ot['approved_minutes']];
            if (! $overtimeEnabled) {
                continue; // no money line; missing rate does not block
            }
            $rate = $this->overtimeRateForDate($comps, $ot['work_date']);
            if ($rate === null) {
                throw new PayrollCalculationException(PayrollErrorCode::OvertimeRateMissing, ['work_date' => $ot['work_date'], 'approval_id' => $ot['approval_id']]);
            }
            $overtimeItems[] = new OvertimeItem($ot['approval_id'], $ot['work_date'], $ot['approved_minutes'], $rate);
        }

        // 6. Manual adjustments (Phase 2B) for this (period, employee). Authoritative
        // inputs, re-read every calculation and OWNED BY THE PERIOD (so a replacement
        // run consumes the same rows); a fixed non-prorated earning/deduction in the
        // entry's resolved currency. internal_reason never enters input/snapshot.
        $adjustmentRows = PayrollAdjustment::query()
            ->where('payroll_period_id', (string) $period->getKey())
            ->where('employee_id', $employee->getKey())
            ->orderBy('id')
            ->get();

        $adjustmentItems = [];
        $adjustmentsForSnapshot = [];
        foreach ($adjustmentRows as $adj) {
            if ($adj->currency !== null && CurrencyMetadata::normalize((string) $adj->currency) !== $currency) {
                throw new PayrollCalculationException(PayrollErrorCode::AdjustmentCurrencyMismatch, ['adjustment_id' => (string) $adj->getKey()]);
            }
            $adjustmentItems[] = new AdjustmentItem((string) $adj->getKey(), (string) $adj->direction, (int) $adj->amount_minor, (string) $adj->employee_visible_label);
            $adjustmentsForSnapshot[] = [
                'id' => (string) $adj->getKey(),
                'version' => (int) $adj->version,
                'direction' => (string) $adj->direction,
                'amount_minor' => (int) $adj->amount_minor,
                'currency' => $adj->currency,
                'employee_visible_label' => (string) $adj->employee_visible_label,
                'source_payroll_entry_id' => $adj->source_payroll_entry_id !== null ? (string) $adj->source_payroll_entry_id : null,
            ];
        }

        $input = new CalculationInput(
            currency: $currency,
            periodExpectedMinutes: $periodExpected,
            baseSegments: $baseSegments,
            componentSegments: $componentSegments,
            unpaidLeaveSegments: $unpaidSegments,
            overtimeItems: $overtimeItems,
            overtimeEnabled: $overtimeEnabled,
            adjustments: $adjustmentItems,
        );

        $scheduleDaysForSnapshot = [];
        foreach ($days as $d => $info) {
            if ($info['minutes'] > 0) {
                $scheduleDaysForSnapshot[$d] = $info['minutes'];
            }
        }
        ksort($scheduleDaysForSnapshot);

        // Explicit canonical ordering of the unordered snapshot collections, so the
        // fingerprint depends only on content (the fingerprint service preserves list
        // order rather than blindly sorting arbitrary lists).
        usort($compRowsForSnapshot, fn ($a, $b) => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
        usort($componentRowsForSnapshot, fn ($a, $b) => [$a['effective_from'], $a['component_id'], $a['assignment_id']] <=> [$b['effective_from'], $b['component_id'], $b['assignment_id']]);
        usort($leaveForSnapshot, fn ($a, $b) => [$a['work_date'], $a['leave_request_id'], (string) $a['leave_type_id']] <=> [$b['work_date'], $b['leave_request_id'], (string) $b['leave_type_id']]);
        usort($overtimeForSnapshot, fn ($a, $b) => [$a['work_date'], $a['approval_id']] <=> [$b['work_date'], $b['approval_id']]);
        usort($adjustmentsForSnapshot, fn ($a, $b) => $a['id'] <=> $b['id']);

        $snapshot = [
            'schema_version' => 1,
            'calculation_version' => PayrollCalculationEngine::VERSION,
            'period' => ['id' => (string) $period->getKey(), 'start' => $periodStart, 'end' => $periodEnd, 'timezone' => $tz],
            'settings' => ['overtime_pay_enabled' => $overtimeEnabled],
            'employment' => ['hire_date' => $hire, 'termination_date' => $term],
            'schedule' => ['period_expected_minutes' => $periodExpected, 'days' => $scheduleDaysForSnapshot],
            'compensations' => $compRowsForSnapshot,
            'components' => $componentRowsForSnapshot,
            'leave' => $leaveForSnapshot,
            'overtime' => $overtimeForSnapshot,
            'adjustments' => $adjustmentsForSnapshot,
        ];

        $employeeSnapshot = [
            'employee_number' => $employee->employee_number,
            'name' => $employee->fullName(),
            'job_title' => $employee->jobTitle?->name,
        ];

        return new PreparedCalculation($input, $snapshot, $employeeSnapshot);
    }

    /** @param array<int, BaseSegment> $segments */
    private function baseSegmentForDate(array $segments, string $date): ?BaseSegment
    {
        foreach ($segments as $s) {
            $to = $s->effectiveTo ?? '9999-12-31';
            if ($date >= $s->effectiveFrom && $date <= $to) {
                return $s;
            }
        }

        return null;
    }

    /** @param Collection<int, EmployeeCompensation> $comps */
    private function overtimeRateForDate($comps, string $date): ?int
    {
        foreach ($comps as $comp) {
            $from = $comp->effective_from->toDateString();
            $to = $comp->effective_to?->toDateString() ?? '9999-12-31';
            if ($date >= $from && $date <= $to) {
                return $comp->overtime_rate_minor_per_hour !== null ? (int) $comp->overtime_rate_minor_per_hour : null;
            }
        }

        return null;
    }

    /** @param array<string, array{minutes:int, intervals:array}> $days */
    private function sumMinutes(array $days, string $from, string $to): int
    {
        $sum = 0;
        foreach ($days as $d => $info) {
            if ($d >= $from && $d <= $to) {
                $sum += $info['minutes'];
            }
        }

        return $sum;
    }

    /** @param array<int, string|null> $dates */
    private function maxDate(array $dates): string
    {
        $vals = array_values(array_filter($dates, fn ($d) => $d !== null));

        return max($vals);
    }

    /** @param array<int, string|null> $dates */
    private function minDate(array $dates): string
    {
        $vals = array_values(array_filter($dates, fn ($d) => $d !== null));

        return min($vals);
    }
}
