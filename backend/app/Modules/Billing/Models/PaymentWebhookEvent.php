<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Infrastructure log of provider webhook events (no provider integrated yet).
 * NOT tenant-owned. Never stores secrets or raw payloads.
 */
class PaymentWebhookEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'provider', 'external_event_id', 'event_type', 'payload_hash',
        'tenant_id', 'received_at', 'processed_at', 'status', 'error',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
