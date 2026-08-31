<?php

namespace Tests\Unit\Tenancy;

use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Queue\ApplyTenantContext;
use Tests\TestCase;

/**
 * Queued jobs must carry EXPLICIT tenant context and must not rely on ambient
 * state (Sprint 0 requirement, section 12). The ApplyTenantContext middleware
 * establishes the captured tenant around handle() and clears it afterwards.
 */
class QueueTenantContextTest extends TestCase
{
    public function test_job_middleware_establishes_and_clears_tenant_context(): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $this->assertFalse($context->hasTenant());

        // A stand-in job that carries a captured tenant id.
        $job = new class
        {
            public function tenantContextId(): ?string
            {
                return '01TENANTTENANTTENANTTENANT';
            }
        };

        $seenInsideHandle = null;
        (new ApplyTenantContext)->handle($job, function () use ($context, &$seenInsideHandle) {
            $seenInsideHandle = $context->tenantId();
        });

        // Context was established during handling...
        $this->assertSame('01TENANTTENANTTENANTTENANT', $seenInsideHandle);
        // ...and always cleared afterwards (no leak to the next job).
        $this->assertFalse($context->hasTenant());
    }

    public function test_job_without_captured_tenant_runs_closed_not_ambient(): void
    {
        $context = app(TenantContext::class);
        // Simulate leftover ambient state from a prior job.
        $context->setTenant('01PREVIOUSPREVIOUSPREVIOUS');

        $job = new class
        {
            public function tenantContextId(): ?string
            {
                return null;
            }
        };

        $seen = 'unset';
        (new ApplyTenantContext)->handle($job, function () use ($context, &$seen) {
            $seen = $context->tenantId();
        });

        // A job with no captured tenant must NOT inherit ambient state.
        $this->assertNull($seen);
        $this->assertFalse($context->hasTenant());
    }
}
