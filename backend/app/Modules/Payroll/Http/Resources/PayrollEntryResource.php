<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payroll entry for management review (sensitive: totals). Lines are included
 * only when eager-loaded (detail view). Employee identity prefers the calculation
 * snapshot so historical presentation is stable, falling back to the current row.
 */
class PayrollEntryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $snapshot = $this->employee_snapshot ?? [];

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => [
                'employee_number' => $snapshot['employee_number'] ?? $this->employee?->employee_number,
                'name' => $snapshot['name'] ?? $this->employee?->fullName(),
                'job_title' => $snapshot['job_title'] ?? $this->employee?->jobTitle?->name,
            ],
            'status' => $this->status->value,
            'currency' => $this->currency,
            'gross_minor' => $this->gross_minor !== null ? (int) $this->gross_minor : null,
            'deduction_minor' => $this->deduction_minor !== null ? (int) $this->deduction_minor : null,
            'net_minor' => $this->net_minor !== null ? (int) $this->net_minor : null,
            'negative_net' => $this->net_minor !== null && (int) $this->net_minor < 0,
            'error_code' => $this->error_code,
            'error_context' => $this->error_context,
            'calculation_version' => $this->calculation_version,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'lines' => PayrollEntryLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
