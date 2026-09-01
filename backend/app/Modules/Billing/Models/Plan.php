<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\PlanStatus;
use App\Modules\Billing\Enums\PlanVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-global subscription plan. NOT tenant-owned: no BelongsToTenant, no
 * RLS. Prices are integer minor units. Feature entitlements live in plan_features.
 */
class Plan extends Model
{
    use HasUlids;

    protected $fillable = [
        'name', 'slug', 'description', 'status', 'visibility',
        'monthly_price_minor', 'annual_price_minor', 'currency',
        'trial_days', 'employee_limit', 'sort_order', 'is_featured', 'is_default_trial',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'visibility' => PlanVisibility::class,
            'monthly_price_minor' => 'integer',
            'annual_price_minor' => 'integer',
            'trial_days' => 'integer',
            'employee_limit' => 'integer',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'is_default_trial' => 'boolean',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** The single platform-configured default trial plan, if any. */
    public static function defaultTrial(): ?self
    {
        return static::query()->where('is_default_trial', true)->where('status', PlanStatus::Active->value)->first();
    }

    public function isActive(): bool
    {
        return $this->status === PlanStatus::Active;
    }

    /** Price in minor units for a billing interval string (monthly|annual). */
    public function priceMinorFor(string $interval): int
    {
        return $interval === 'annual' ? $this->annual_price_minor : $this->monthly_price_minor;
    }
}
