<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BankTransferStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's bank-transfer proof submission. proof_storage_key is a PRIVATE
 * storage key and is hidden from serialization (never a public URL).
 */
class BankTransferSubmission extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'amount_minor', 'currency', 'transfer_reference',
        'proof_storage_key', 'original_filename', 'mime_type', 'size', 'status',
        'submitted_by_user_id', 'reviewed_by_platform_admin_id', 'reviewed_at',
        'rejection_reason', 'payment_id',
    ];

    protected $hidden = ['proof_storage_key'];

    protected function casts(): array
    {
        return [
            'status' => BankTransferStatus::class,
            'amount_minor' => 'integer',
            'size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
