<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanFeature;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single place that answers entitlement/usage questions (spec §3, §4, §25).
 * Controllers/modules must NOT scatter plan checks — they ask this service.
 *
 * FAIL-CLOSED (owner policy): product entitlements require an explicit USABLE
 * commercial state (trialing / active / grace_period). "No subscription",
 * expired, suspended, or canceled grant NO product entitlement — there is no
 * implicit unlimited fallback. Billing/account/recovery routes never call this,
 * so they stay reachable regardless of subscription state.
 */
class EntitlementService
{
    /** Employment statuses that do NOT count toward the plan employee limit. */
    private const NON_COUNTABLE_STATUSES = ['terminated', 'archived'];

    public function __construct(private readonly TenantContext $context) {}

    /** The tenant's single primary subscription (or null). */
    public function subscription(): ?Subscription
    {
        return Subscription::query()->with('plan')->first();
    }

    public function plan(): ?Plan
    {
        return $this->subscription()?->plan;
    }

    public function subscriptionUsable(): bool
    {
        return (bool) $this->subscription()?->isUsable();
    }

    // --- Employee limit -----------------------------------------------------

    /** Number of employees that count toward the plan limit. */
    public function countableEmployees(): int
    {
        return Employee::query()
            ->whereNotIn('employment_status', self::NON_COUNTABLE_STATUSES)
            ->count();
    }

    /**
     * Plan employee limit for display. null means "unlimited" ONLY within a
     * usable subscription whose plan sets no limit; callers deciding whether a
     * create is allowed must use canAddEmployee()/assertCanAddEmployee(), which
     * are fail-closed.
     */
    public function employeeLimit(): ?int
    {
        return $this->plan()?->employee_limit;
    }

    public function remainingEmployeeSlots(): ?int
    {
        if (! $this->subscriptionUsable()) {
            return 0; // fail-closed: no usable entitlement
        }
        $limit = $this->employeeLimit();
        if ($limit === null) {
            return null; // unlimited within the usable plan
        }

        return max(0, $limit - $this->countableEmployees());
    }

    public function canAddEmployee(): bool
    {
        if (! $this->subscriptionUsable()) {
            return false;
        }
        $remaining = $this->remainingEmployeeSlots();

        return $remaining === null || $remaining > 0;
    }

    /**
     * Enforce entitlement at the employee-creation entry point. Throws a
     * localized 422: subscription_required when there is no usable subscription,
     * else employee_limit_reached when the plan cap is hit.
     */
    public function assertCanAddEmployee(): void
    {
        if (! $this->subscriptionUsable()) {
            throw ValidationException::withMessages([
                'subscription' => [__('billing.subscription_required')],
            ]);
        }
        $remaining = $this->remainingEmployeeSlots();
        if ($remaining !== null && $remaining <= 0) {
            throw ValidationException::withMessages([
                'employee_limit' => [__('billing.employee_limit_reached', [
                    'limit' => (string) $this->employeeLimit(),
                ])],
            ]);
        }
    }

    /**
     * Run an employee-creating closure inside ONE transaction guarded by a
     * per-tenant PostgreSQL advisory lock, so the entitlement check and the
     * insert share a concurrency boundary (spec §4). Two concurrent creates at
     * limit-1 cannot both pass: the second waits for the lock, then sees the
     * updated count and is rejected.
     *
     * @template T
     *
     * @param  Closure():T  $create
     * @return T
     */
    public function guardedEmployeeCreate(Closure $create): mixed
    {
        return DB::transaction(function () use ($create) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
                    'employee_limit', (string) $this->context->tenantId(),
                ]);
            }
            $this->assertCanAddEmployee();

            return $create();
        });
    }

    // --- Generic feature entitlements --------------------------------------

    public function feature(string $featureKey): ?PlanFeature
    {
        $plan = $this->plan();
        if ($plan === null) {
            return null;
        }

        return $plan->features->firstWhere('feature_key', $featureKey)
            ?? $plan->features()->where('feature_key', $featureKey)->first();
    }

    /** Deny-by-default: usable only when the subscription is usable AND enables it. */
    public function canUseFeature(string $featureKey): bool
    {
        if (! $this->subscriptionUsable()) {
            return false;
        }

        return (bool) $this->feature($featureKey)?->enabled;
    }

    public function featureLimit(string $featureKey): ?int
    {
        return $this->feature($featureKey)?->limit_value;
    }
}
