<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for "which tenant is this request/job acting as".
 *
 * It keeps an in-memory tenant id (used by the application-layer global scope)
 * AND pushes that id into the PostgreSQL session GUC `app.tenant_id`, which the
 * RLS policies read. The two layers are always set together so they cannot
 * disagree.
 *
 * Leak-safety (per Sprint 0 requirement): the GUC is session-level so it works
 * across the many queries of one HTTP request, and it is ALWAYS reset via
 * clear() (middleware terminate, job finally-blocks, test tearDown). Nothing
 * relies on ambient state persisting to the next request or job.
 */
class TenantContext
{
    private ?string $tenantId = null;

    private ?Tenant $tenant = null;

    private bool $platformReadonly = false;

    /**
     * The authenticated user id, used only so a logged-in user can read THEIR
     * OWN tenant memberships (e.g. a tenant switcher) before a tenant context is
     * chosen. It never grants access to other users' rows.
     */
    private ?string $userId = null;

    public function setTenant(Tenant|string $tenant): void
    {
        if ($tenant instanceof Tenant) {
            $this->tenant = $tenant;
            $this->tenantId = $tenant->getKey();
        } else {
            $this->tenant = null;
            $this->tenantId = $tenant;
        }

        // Entering a tenant context always disables platform read-only mode.
        $this->platformReadonly = false;
        $this->applyToDatabase();
    }

    public function setUser(?string $userId): void
    {
        $this->userId = $userId;
        $this->applyToDatabase();
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::query()->find($this->tenantId);
        }

        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function isPlatformReadonly(): bool
    {
        return $this->platformReadonly;
    }

    /** Reset ALL context and the database GUCs to a closed-by-default state. */
    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenant = null;
        $this->platformReadonly = false;
        $this->userId = null;
        $this->applyToDatabase();
    }

    /** Run a callback scoped to a specific tenant, then restore prior state. */
    public function runAs(Tenant|string $tenant, Closure $callback): mixed
    {
        $previousId = $this->tenantId;
        $previousTenant = $this->tenant;
        $previousPlatform = $this->platformReadonly;

        try {
            $this->setTenant($tenant);

            return $callback();
        } finally {
            $this->restore($previousId, $previousTenant, $previousPlatform);
        }
    }

    /**
     * Run a callback with audited platform read-only visibility across tenants.
     * Only ever called from the Super Admin portal. Writes still cannot bypass
     * tenant scope (RLS WITH CHECK remains tenant-only).
     */
    public function runAsPlatform(Closure $callback): mixed
    {
        $previousId = $this->tenantId;
        $previousTenant = $this->tenant;
        $previousPlatform = $this->platformReadonly;

        try {
            $this->tenantId = null;
            $this->tenant = null;
            $this->platformReadonly = true;
            $this->applyToDatabase();

            return $callback();
        } finally {
            $this->restore($previousId, $previousTenant, $previousPlatform);
        }
    }

    private function restore(?string $id, ?Tenant $tenant, bool $platform): void
    {
        $this->tenantId = $id;
        $this->tenant = $tenant;
        $this->platformReadonly = $platform;
        $this->applyToDatabase();
    }

    /** Push the current context into the PostgreSQL session GUCs. */
    private function applyToDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        // set_config(name, value, is_local=false) => session scope, reset by us.
        DB::statement("select set_config('app.tenant_id', ?, false)", [$this->tenantId ?? '']);
        DB::statement("select set_config('app.platform_readonly', ?, false)", [$this->platformReadonly ? 'on' : 'off']);
        DB::statement("select set_config('app.user_id', ?, false)", [$this->userId ?? '']);
    }
}
