<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Billing\Services\EntitlementService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * Sprint 9 — AI HTTP surface: ai.use route gating and the response envelope.
 */
class AiApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function enableAi(): void
    {
        $this->app->instance(AiProvider::class, new FakeAiProvider(true));
        $this->app->bind(EntitlementService::class, fn () => new class(app(TenantContext::class)) extends EntitlementService
        {
            public function canUseFeature(string $featureKey): bool
            {
                return true;
            }

            public function featureLimit(string $featureKey): ?int
            {
                return null;
            }
        });
    }

    public function test_route_requires_ai_use_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->memberWithRole($tenant, 'employee'); // employee lacks ai.use

        $this->actingAs($employee)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/ai/availability')
            ->assertForbidden();
    }

    public function test_availability_endpoint_reports_disabled_by_default(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        // No fake bound → the default NullAiProvider is used → disabled.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/ai/availability')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'disabled');
    }

    public function test_insight_endpoint_returns_summary_when_enabled(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->enableAi();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/ai/insights', ['feature' => 'dashboard_summary'])
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.feature', 'dashboard_summary');
    }

    public function test_insight_rejects_unknown_feature(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->enableAi();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/ai/insights', ['feature' => 'delete_everything'])
            ->assertStatus(422);
    }
}
