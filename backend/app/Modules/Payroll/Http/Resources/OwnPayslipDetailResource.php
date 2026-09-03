<?php

namespace App\Modules\Payroll\Http\Resources;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Employee self-service payslip detail — safe, read-only projection over one
 * finalized payroll entry and its immutable lines, grouped into earnings and
 * deductions by line.direction (never by sign). Totals are the finalized entry's
 * canonical values (never recomputed). Excludes every internal/private field:
 * input_snapshot, fingerprints, error context, negative-net override reason/actor,
 * calculation requester/approver/finalizer, adjustment internal_reason,
 * source_payroll_entry_id, created_by, raw source ids, and metadata.
 */
class OwnPayslipDetailResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $snapshot = $this->employee_snapshot ?? [];
        $period = $this->run?->period;

        $lines = $this->lines->map(fn ($line) => [
            'line_type' => $line->line_type->value,
            'label' => $line->label_snapshot,
            'quantity_minutes' => $line->quantity_minutes !== null ? (int) $line->quantity_minutes : null,
            'rate_minor_per_hour' => $line->rate_minor_per_hour !== null ? (int) $line->rate_minor_per_hour : null,
            'amount_minor' => (int) $line->amount_minor,
            'direction' => $line->direction->value,
        ]);

        $earnings = $lines->where('direction', PayrollLineDirection::Earning->value)->map(fn ($l) => Arr::except($l, ['direction']))->values();
        $deductions = $lines->where('direction', PayrollLineDirection::Deduction->value)->map(fn ($l) => Arr::except($l, ['direction']))->values();

        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'gross_minor' => (int) $this->gross_minor,
            'deduction_minor' => (int) $this->deduction_minor,
            'net_minor' => (int) $this->net_minor,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'period' => [
                'id' => $period?->id,
                'label' => $period?->label,
                'start' => $period?->period_start?->toDateString(),
                'end' => $period?->period_end?->toDateString(),
            ],
            'employee' => [
                'employee_number' => $snapshot['employee_number'] ?? null,
                'name' => $snapshot['name'] ?? null,
                'job_title' => $snapshot['job_title'] ?? null,
            ],
            'company' => [
                'name' => $this->company_name,
            ],
            'earnings' => $earnings,
            'deductions' => $deductions,
        ];
    }
}
