<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EmployeeLimitTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function createEmployeeViaApi($owner, $tenant, string $first)
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => $first, 'last_name' => 'Test']);
    }

    public function test_creating_under_the_limit_succeeds(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->subscribeTenant($tenant, $this->makePlan(['employee_limit' => 5, 'trial_days' => 0]), ['trial' => false]);

        $this->createEmployeeViaApi($owner, $tenant, 'Aisha')->assertCreated();
    }

    public function test_creating_at_the_limit_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->subscribeTenant($tenant, $this->makePlan(['employee_limit' => 2, 'trial_days' => 0]), ['trial' => false]);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-2']);

        $this->createEmployeeViaApi($owner, $tenant, 'Third')->assertStatus(422)
            ->assertJsonPath('errors.employee_limit.0', __('billing.employee_limit_reached', ['limit' => '2']));
    }

    public function test_terminated_and_archived_employees_do_not_count(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->subscribeTenant($tenant, $this->makePlan(['employee_limit' => 2, 'trial_days' => 0]), ['trial' => false]);
        $e1 = $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-2']);

        // At the cap -> rejected.
        $this->createEmployeeViaApi($owner, $tenant, 'Blocked')->assertStatus(422);

        // Terminate one -> a slot frees up.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/employees/{$e1->id}/status", ['employment_status' => 'terminated'])->assertOk();

        $this->createEmployeeViaApi($owner, $tenant, 'NowAllowed')->assertCreated();
    }

    public function test_upgrading_to_a_higher_plan_allows_growth_after_payment(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $small = $this->makePlan(['name' => 'Small', 'employee_limit' => 1, 'monthly_price_minor' => 1000, 'trial_days' => 0]);
        $big = $this->makePlan(['name' => 'Big', 'employee_limit' => 50, 'monthly_price_minor' => 5000, 'trial_days' => 0]);
        $this->subscribeTenant($tenant, $small, ['trial' => false]);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);

        $this->createEmployeeViaApi($owner, $tenant, 'Blocked')->assertStatus(422);

        // Request the upgrade (payment-gated) — limits do NOT rise yet.
        $invoiceId = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $big->id])
            ->assertOk()->json('invoice.id');
        $this->createEmployeeViaApi($owner, $tenant, 'StillBlocked')->assertStatus(422);

        // Pay the upgrade invoice -> higher plan applies -> growth allowed.
        $invoice = $this->withinTenant($tenant, fn () => Invoice::query()->findOrFail($invoiceId));
        $this->payInvoiceFully($tenant, $invoice);

        $this->createEmployeeViaApi($owner, $tenant, 'NowAllowed')->assertCreated();
    }

    public function test_tenant_without_subscription_cannot_create_employees(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        // Fail-closed: no usable subscription -> creation rejected (not unlimited).
        $this->createEmployeeViaApi($owner, $tenant, 'Blocked')->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', __('billing.subscription_required'));
    }
}
