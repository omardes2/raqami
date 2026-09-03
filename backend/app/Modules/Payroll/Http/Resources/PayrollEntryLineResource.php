<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A single payroll line for management review (sensitive: amounts). */
class PayrollEntryLineResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_code' => $this->line_code,
            'line_type' => $this->line_type->value,
            'direction' => $this->direction->value,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'label' => $this->label_snapshot,
            'quantity_minutes' => $this->quantity_minutes !== null ? (int) $this->quantity_minutes : null,
            'rate_minor_per_hour' => $this->rate_minor_per_hour !== null ? (int) $this->rate_minor_per_hour : null,
            'rate_bps' => $this->rate_bps !== null ? (int) $this->rate_bps : null,
            'amount_minor' => (int) $this->amount_minor,
            'metadata' => $this->metadata,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
