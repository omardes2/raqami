<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant's single primary subscription. Belongs to the TENANT (never a user).
 * Tenant-owned (tenant_id + RLS).
 */
class Subscription extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_interval', 'currency',
        'started_at', 'trial_started_at', 'trial_ends_at',
        'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'canceled_at', 'ended_at', 'grace_ends_at',
        'payment_provider', 'provider_subscription_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'started_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }

    public function isUsable(): bool
    {
        return $this->status instanceof SubscriptionStatus && $this->status->isUsable();
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }
}
