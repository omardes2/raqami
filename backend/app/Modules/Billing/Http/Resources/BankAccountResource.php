<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 *
 * internal_notes is hidden on the model; $platform toggles whether admin-only
 * config fields (status) are surfaced.
 */
class BankAccountResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'bank_name' => $this->bank_name,
            'account_holder' => $this->account_holder,
            'account_number' => $this->account_number,
            'swift_code' => $this->swift_code,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'instructions' => $this->instructions,
            'status' => $this->status,
        ];
    }
}
