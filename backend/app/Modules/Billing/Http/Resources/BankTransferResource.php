<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\BankTransferSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankTransferSubmission
 *
 * Note: proof_storage_key is hidden on the model and never serialized here.
 */
class BankTransferResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'transfer_reference' => $this->transfer_reference,
            'original_filename' => $this->original_filename,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
