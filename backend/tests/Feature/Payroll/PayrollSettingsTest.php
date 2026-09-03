<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PayrollSettingsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_onboarding_creates_one_settings_row_with_safe_defaults(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $rows = PayrollSetting::query()->get();
            $this->assertCount(1, $rows);
            $settings = $rows->first();
            $this->assertFalse((bool) $settings->overtime_pay_enabled);
            $this->assertFalse((bool) $settings->require_four_eyes);
            $this->assertFalse((bool) $settings->allow_self_payroll);
        });
    }

    public function test_get_or_create_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $svc = app(PayrollSettingsService::class);
            $a = $svc->getOrCreate();
            $b = $svc->getOrCreate();
            $this->assertSame($a->getKey(), $b->getKey());
            $this->assertSame(1, PayrollSetting::query()->count());
        });
    }

    public function test_backfill_command_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        // Simulate a pre-Sprint-7 tenant by deleting its settings row.
        $this->withinTenant($tenant, fn () => PayrollSetting::query()->delete());

        $this->artisan('payroll:bootstrap-settings')->assertSuccessful();
        $this->artisan('payroll:bootstrap-settings')->assertSuccessful();

        $this->withinTenant($tenant, function () {
            $this->assertSame(1, PayrollSetting::query()->count());
        });
    }

    public function test_update_via_api_validates_timezone(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson('/api/payroll/settings', ['payroll_timezone' => 'Not/AZone'])
            ->assertStatus(422)->assertJsonValidationErrors('payroll_timezone');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson('/api/payroll/settings', ['payroll_timezone' => 'Asia/Hebron', 'overtime_pay_enabled' => true])
            ->assertOk()->assertJsonPath('payroll_timezone', 'Asia/Hebron')->assertJsonPath('overtime_pay_enabled', true);
    }

    public function test_settings_are_tenant_isolated(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();

        $idA = $this->withinTenant($tenantA, fn () => PayrollSetting::query()->value('id'));

        // Tenant B's scope never sees tenant A's settings row.
        $this->withinTenant($tenantB, function () use ($idA) {
            $this->assertNull(PayrollSetting::query()->whereKey($idA)->first());
        });
        app(TenantContext::class)->clear();
    }
}
