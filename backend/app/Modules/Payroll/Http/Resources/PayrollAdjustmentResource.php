<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A manual payroll adjustment for management review (sensitive: amounts + the private
 * internal_reason). Only ever returned from company-level-authorized endpoints.
 */
class PayrollAdjustmentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_period_id' => $this->payroll_period_id,
            'employee_id' => $this->employee_id,
            'employee_visible_label' => $this->employee_visible_label,
            'direction' => $this->direction,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'internal_reason' => $this->internal_reason,
            'source_payroll_entry_id' => $this->source_payroll_entry_id,
            'version' => (int) $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
