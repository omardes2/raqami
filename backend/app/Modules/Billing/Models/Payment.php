<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment record. Success is applied transactionally to its invoice. */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'subscription_id', 'method', 'provider',
        'provider_payment_id', 'amount_minor', 'currency', 'status', 'reference',
        'paid_at', 'approved_at', 'approved_by_platform_admin_id',
        'recorded_by_user_id', 'failed_reason', 'idempotency_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
