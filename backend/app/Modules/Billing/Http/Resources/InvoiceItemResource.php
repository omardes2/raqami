<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceItem */
class InvoiceItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount_minor' => $this->unit_amount_minor,
            'subtotal_minor' => $this->subtotal_minor,
        ];
    }
}
