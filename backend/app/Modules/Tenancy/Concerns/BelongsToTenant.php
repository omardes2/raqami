<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Marks a model as tenant-owned. Adds the global TenantScope and stamps
 * tenant_id automatically on creation from the active TenantContext, so callers
 * cannot forget it and cannot forge a different tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);
            $column = $model->getTenantColumn();

            if (empty($model->{$column})) {
                if (! $context->hasTenant()) {
                    throw new RuntimeException(
                        'Cannot create '.$model::class.' without an active tenant context.'
                    );
                }
                $model->{$column} = $context->tenantId();
            } elseif ($context->hasTenant() && $model->{$column} !== $context->tenantId()) {
                // Never allow a write stamped for a different tenant.
                throw new RuntimeException('Cross-tenant write blocked for '.$model::class.'.');
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, $this->getTenantColumn());
    }
}
