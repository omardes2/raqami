<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded plan change (immediate upgrade / scheduled downgrade). */
class SubscriptionChange extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'from_plan_id', 'to_plan_id',
        'change_type', 'effective_at', 'status', 'requested_by_user_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
