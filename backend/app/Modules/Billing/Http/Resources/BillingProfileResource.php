<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\BillingProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BillingProfile */
class BillingProfileResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'billing_email' => $this->billing_email,
            'billing_phone' => $this->billing_phone,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'postal_code' => $this->postal_code,
            'tax_id' => $this->tax_id,
            'preferred_currency' => $this->preferred_currency,
            'invoice_notes' => $this->invoice_notes,
        ];
    }
}
