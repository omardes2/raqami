<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Currency-neutral: no currency or scalar totals (grouped-by-currency arrive in Phase 2). */
class PayrollRunResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_period_id' => $this->payroll_period_id,
            'status' => $this->status?->value,
            'calculation_version' => $this->calculation_version,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'version' => (int) $this->version,
        ];
    }
}
