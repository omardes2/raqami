<?php

namespace App\Support\Queue;

use App\Modules\Tenancy\Services\TenantContext;

/**
 * Opt-in for queued jobs that operate on tenant data. Jobs MUST NOT rely on
 * ambient HTTP tenant state in the worker. A job using this trait captures the
 * active tenant id at dispatch time and re-establishes it (and clears it)
 * around handle() via the ApplyTenantContext middleware.
 *
 * Usage:
 *   class SyncSomething implements ShouldQueue {
 *       use Queueable, TenantAware;
 *       public function __construct() { $this->captureTenantContext(); }
 *   }
 */
trait TenantAware
{
    public ?string $tenantContextId = null;

    public function captureTenantContext(): static
    {
        $this->tenantContextId = app(TenantContext::class)->tenantId();

        return $this;
    }

    public function tenantContextId(): ?string
    {
        return $this->tenantContextId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContext];
    }
}
