<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee self-service payslip list item — a safe, read-only projection over ONE
 * finalized payroll entry. Deliberately excludes input_snapshot, fingerprints,
 * error/override/approval/traceability, and all internal metadata. Historical
 * identity comes from the immutable employee_snapshot.
 */
class OwnPayslipResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $snapshot = $this->employee_snapshot ?? [];
        $period = $this->run?->period;

        return [
            'id' => $this->id,
            'payroll_period_id' => $this->run?->payroll_period_id,
            'period_label' => $period?->label,
            'period_start' => $period?->period_start?->toDateString(),
            'period_end' => $period?->period_end?->toDateString(),
            'currency' => $this->currency,
            'gross_minor' => (int) $this->gross_minor,
            'deduction_minor' => (int) $this->deduction_minor,
            'net_minor' => (int) $this->net_minor,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'employee_number' => $snapshot['employee_number'] ?? null,
            'employee_name' => $snapshot['name'] ?? null,
        ];
    }
}
