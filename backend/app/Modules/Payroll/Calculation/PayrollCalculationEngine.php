<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Calculation\Input\CalculationInput;
use App\Modules\Payroll\Enums\PayrollComponentType;
use App\Modules\Payroll\Enums\PayrollLineDirection;
use App\Modules\Payroll\Enums\PayrollLineType;
use App\Modules\Payroll\Support\PayrollMoney;

/**
 * The pure payroll calculation engine (calculation version core-v1). Deterministic
 * integer/rational arithmetic only — no DB, no HTTP, no queue, no audit, no float.
 * Every money value flows through PayrollMoney's overflow-safe HALF-UP helpers.
 *
 * Proration model: each amount = monthly * payable_minutes / period_expected_minutes.
 * The denominator is the FULL-month scheduled basis; hire/termination/leave only
 * change numerators. Unpaid leave is its OWN deduction line — it never reduces the
 * base numerator (that would double-deduct).
 */
class PayrollCalculationEngine
{
    public const VERSION = 'core-v1';

    public function calculate(CalculationInput $input): CalculationResult
    {
        $denominator = $input->periodExpectedMinutes;
        $lines = [];

        // 1. Base salary — one earning line per compensation segment.
        foreach ($input->baseSegments as $segment) {
            $amount = PayrollMoney::mulDivHalfUp($segment->monthlyBaseMinor, $segment->payableMinutes, $denominator);
            if ($amount === 0) {
                continue;
            }
            $lines[] = new CalculatedLine(
                type: PayrollLineType::BaseSalary,
                direction: PayrollLineDirection::Earning,
                sourceType: 'employee_compensation',
                sourceId: $segment->compensationId,
                label: 'Base salary',
                amountMinor: $amount,
                quantityMinutes: $segment->payableMinutes,
                metadata: ['effective_from' => $segment->effectiveFrom, 'effective_to' => $segment->effectiveTo],
            );
        }

        // 2. Recurring components — earning or deduction per catalog type.
        foreach ($input->componentSegments as $segment) {
            $monthly = $segment->fixedAmountMinor
                ?? PayrollMoney::mulDivHalfUp($segment->monthlyBaseMinor, (int) $segment->rateBps, 10000);
            $amount = PayrollMoney::mulDivHalfUp($monthly, $segment->payableMinutes, $denominator);
            if ($amount === 0) {
                continue;
            }
            $isEarning = $segment->componentType === PayrollComponentType::Earning;
            $lines[] = new CalculatedLine(
                type: $isEarning ? PayrollLineType::ComponentEarning : PayrollLineType::ComponentDeduction,
                direction: $isEarning ? PayrollLineDirection::Earning : PayrollLineDirection::Deduction,
                sourceType: 'employee_compensation_component',
                sourceId: $segment->assignmentId,
                label: $segment->label,
                amountMinor: $amount,
                quantityMinutes: $segment->payableMinutes,
                rateBps: $segment->rateBps,
                metadata: ['code' => $segment->code, 'effective_from' => $segment->effectiveFrom, 'effective_to' => $segment->effectiveTo],
            );
        }

        // 3. Approved overtime (only when the tenant enables overtime pay).
        if ($input->overtimeEnabled) {
            foreach ($input->overtimeItems as $item) {
                $amount = PayrollMoney::mulDivHalfUp($item->rateMinorPerHour, $item->approvedMinutes, 60);
                if ($amount === 0) {
                    continue;
                }
                $lines[] = new CalculatedLine(
                    type: PayrollLineType::Overtime,
                    direction: PayrollLineDirection::Earning,
                    sourceType: 'overtime_approval',
                    sourceId: $item->approvalId,
                    label: 'Overtime',
                    amountMinor: $amount,
                    quantityMinutes: $item->approvedMinutes,
                    rateMinorPerHour: $item->rateMinorPerHour,
                    metadata: ['work_date' => $item->workDate],
                );
            }
        }

        // 4. Unpaid leave — a deduction at the covering segment's monthly base.
        foreach ($input->unpaidLeaveSegments as $segment) {
            $amount = PayrollMoney::mulDivHalfUp($segment->monthlyBaseMinor, $segment->unpaidMinutes, $denominator);
            if ($amount === 0) {
                continue;
            }
            $lines[] = new CalculatedLine(
                type: PayrollLineType::UnpaidLeave,
                direction: PayrollLineDirection::Deduction,
                sourceType: 'leave_request',
                sourceId: $segment->leaveRequestId,
                label: 'Unpaid leave',
                amountMinor: $amount,
                quantityMinutes: $segment->unpaidMinutes,
                metadata: ['date_from' => $segment->dateFrom, 'date_to' => $segment->dateTo],
            );
        }

        // 5. Manual adjustments (Phase 2B) — a fixed, NON-prorated earning or
        // deduction at its full magnitude. Empty by default, so a run with no
        // adjustments is byte-identical to the frozen Phase-2A result.
        foreach ($input->adjustments as $adjustment) {
            if ($adjustment->amountMinor === 0) {
                continue;
            }
            $isEarning = $adjustment->direction === PayrollLineDirection::Earning->value;
            $lines[] = new CalculatedLine(
                type: $isEarning ? PayrollLineType::AdjustmentEarning : PayrollLineType::AdjustmentDeduction,
                direction: $isEarning ? PayrollLineDirection::Earning : PayrollLineDirection::Deduction,
                sourceType: 'payroll_adjustment',
                sourceId: $adjustment->id,
                label: $adjustment->label,
                amountMinor: $adjustment->amountMinor,
                metadata: ['direction' => $adjustment->direction],
            );
        }

        $gross = 0;
        $deduction = 0;
        foreach ($lines as $line) {
            if ($line->direction === PayrollLineDirection::Earning) {
                $gross += $line->amountMinor;
            } else {
                $deduction += $line->amountMinor;
            }
        }
        $net = $gross - $deduction;

        $warnings = [];
        if ($net < 0) {
            $warnings[] = 'negative_net';
        }

        return new CalculationResult($input->currency, $lines, $gross, $deduction, $net, $warnings);
    }
}
