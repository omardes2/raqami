<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sensitive: contains compensation amounts. Only ever returned from
 * payroll.compensation.view-gated, company-level-authorized endpoints — never
 * from a generic employee resource.
 */
class EmployeeCompensationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'currency' => $this->currency,
            'base_amount_minor' => (int) $this->base_amount_minor,
            'overtime_rate_minor_per_hour' => $this->overtime_rate_minor_per_hour !== null ? (int) $this->overtime_rate_minor_per_hour : null,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'version' => (int) $this->version,
        ];
    }
}
