<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A manual payroll adjustment for management review (sensitive: amounts). */
class PayrollAdjustmentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'employee_id' => $this->employee_id,
            'label' => $this->label,
            'direction' => $this->direction,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
