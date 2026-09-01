<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'invoice_number' => $this->invoice_number,
            'subscription_id' => $this->subscription_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'tax_minor' => $this->tax_minor,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'total_minor' => $this->total_minor,
            'amount_paid_minor' => $this->amount_paid_minor,
            'amount_due_minor' => $this->amount_due_minor,
            'coupon_code' => $this->coupon_code,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at,
            'paid_at' => $this->paid_at,
            'billing_period_start' => $this->billing_period_start,
            'billing_period_end' => $this->billing_period_end,
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
