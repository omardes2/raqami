<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Models\AiUsageEvent;
use App\Modules\Ai\Services\AiInsightService;
use App\Modules\Billing\Services\EntitlementService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Sprint 9 — AI assistant: availability/gating, authorization scope, tenant
 * isolation, sensitive-field exclusion, provider failure, invalid structured
 * output, usage accounting, and rate limiting. No real provider is ever called.
 */
class AiInsightTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function bindAi(bool $enabled = true, bool $entitled = true, string $content = '{"summary":"S","highlights":["a","b"]}', bool $throw = false): FakeAiProvider
    {
        $fake = new FakeAiProvider($enabled, $content, $throw);
        $this->app->instance(AiProvider::class, $fake);
        $this->app->bind(EntitlementService::class, fn () => new class($entitled, app(TenantContext::class)) extends EntitlementService
        {
            public function __construct(private readonly bool $entitled, TenantContext $context)
            {
                parent::__construct($context);
            }

            public function canUseFeature(string $featureKey): bool
            {
                return $this->entitled;
            }

            public function featureLimit(string $featureKey): ?int
            {
                return null;
            }
        });

        return $fake;
    }

    private function service(): AiInsightService
    {
        return $this->app->make(AiInsightService::class);
    }

    private function usageCount(Tenant $tenant): int
    {
        return app(TenantContext::class)->runAs($tenant, fn () => AiUsageEvent::query()->count());
    }

    public function test_availability_disabled_when_provider_off(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi(enabled: false);

        $result = $this->withinTenant($tenant, fn () => $this->service()->availability());
        $this->assertFalse($result['available']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_availability_not_entitled(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi(enabled: true, entitled: false);

        $result = $this->withinTenant($tenant, fn () => $this->service()->availability());
        $this->assertFalse($result['available']);
        $this->assertSame('not_entitled', $result['reason']);
    }

    public function test_dashboard_summary_returns_summary_and_records_usage(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi();

        $result = $this->withinTenant($tenant, fn () => $this->service()->generate($owner, 'dashboard_summary'));

        $this->assertTrue($result->available);
        $this->assertSame('S', $result->summary);
        $this->assertSame(['a', 'b'], $result->highlights);
        $this->assertSame(1, $this->usageCount($tenant));
    }

    public function test_forbidden_when_actor_lacks_report_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->memberWithRole($tenant, 'employee'); // no attendance.reports.view
        $fake = $this->bindAi();

        $result = $this->withinTenant($tenant, fn () => $this->service()->generate($employee, 'attendance_insights'));

        $this->assertFalse($result->available);
        $this->assertSame('forbidden', $result->unavailableReason);
        $this->assertNull($fake->lastRequest, 'no external call may happen when forbidden');
        $this->assertSame(0, $this->usageCount($tenant));
    }

    public function test_prompt_contains_no_sensitive_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $fake = $this->bindAi();

        $this->withinTenant($tenant, fn () => $this->service()->generate($owner, 'dashboard_summary'));

        $payload = json_encode($fake->lastRequest->messages);
        foreach (['salary', 'national_id', 'iban', 'bank', 'net_minor', 'gross'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_provider_failure_is_graceful_and_recorded(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi(throw: true);

        $result = $this->withinTenant($tenant, fn () => $this->service()->generate($owner, 'dashboard_summary'));

        $this->assertFalse($result->available);
        $this->assertSame('provider_error', $result->unavailableReason);
        $status = app(TenantContext::class)->runAs($tenant, fn () => AiUsageEvent::query()->value('status'));
        $this->assertSame('error', $status);
    }

    public function test_invalid_structured_output_falls_back_to_text(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi(content: 'not json at all');

        $result = $this->withinTenant($tenant, fn () => $this->service()->generate($owner, 'dashboard_summary'));

        $this->assertTrue($result->available);
        $this->assertSame('not json at all', $result->summary);
        $this->assertSame([], $result->highlights);
    }

    public function test_rate_limited_when_over_daily_cap(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->bindAi();
        config(['ai.daily_call_cap' => 1]);

        // First call consumes the single allowed slot.
        $this->withinTenant($tenant, fn () => $this->service()->generate($owner, 'dashboard_summary'));

        $result = $this->withinTenant($tenant, fn () => $this->service()->availability());
        $this->assertFalse($result['available']);
        $this->assertSame('rate_limited', $result['reason']);
    }

    public function test_usage_event_is_tenant_scoped(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $this->bindAi();

        $this->withinTenant($tenantA, fn () => $this->service()->generate($ownerA, 'dashboard_summary'));

        $this->assertSame(1, $this->usageCount($tenantA));
        $this->assertSame(0, $this->usageCount($tenantB));
    }
}
