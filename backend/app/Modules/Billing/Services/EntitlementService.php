<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanFeature;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Employees\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * The single place that answers entitlement/usage questions (spec §3, §4, §25).
 * Controllers/modules must NOT scatter plan checks — they ask this service:
 *   - can this tenant use feature X?
 *   - what is the limit for feature Y, and current usage?
 *   - can another employee be added?
 *   - is the subscription usable right now?
 *
 * All queries run within the active tenant context (RLS + global scope), so no
 * tenant_id is passed around and nothing can read another tenant's usage.
 *
 * Employee-limit rule (V1): a tenant with NO active subscription is treated as
 * UNLIMITED (fail-open) so the platform never blocks a company that has not yet
 * chosen a plan; enforcement applies only when a plan with a finite
 * employee_limit is active. Countable employees = employment_status NOT in
 * (terminated, archived) and not soft-deleted.
 */
class EntitlementService
{
    /** Employment statuses that do NOT count toward the plan employee limit. */
    private const NON_COUNTABLE_STATUSES = ['terminated', 'archived'];

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

    /** Plan employee limit, or null for unlimited / no active plan limit. */
    public function employeeLimit(): ?int
    {
        $plan = $this->plan();

        return $plan?->employee_limit;
    }

    public function remainingEmployeeSlots(): ?int
    {
        $limit = $this->employeeLimit();
        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $this->countableEmployees());
    }

    public function canAddEmployee(): bool
    {
        $remaining = $this->remainingEmployeeSlots();

        return $remaining === null || $remaining > 0;
    }

    /**
     * Enforce the employee limit at the creation entry point. Throws a localized
     * 422 when the tenant's plan cap is reached (spec §4).
     */
    public function assertCanAddEmployee(): void
    {
        if (! $this->canAddEmployee()) {
            throw ValidationException::withMessages([
                'employee_limit' => [__('billing.employee_limit_reached', [
                    'limit' => (string) $this->employeeLimit(),
                ])],
            ]);
        }
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

    /** Deny-by-default: a feature is usable only when the plan enables it. */
    public function canUseFeature(string $featureKey): bool
    {
        if (! $this->subscriptionUsable()) {
            return false;
        }

        return (bool) $this->feature($featureKey)?->enabled;
    }

    /** Numeric limit for a feature (null = unlimited or not configured). */
    public function featureLimit(string $featureKey): ?int
    {
        return $this->feature($featureKey)?->limit_value;
    }
}
