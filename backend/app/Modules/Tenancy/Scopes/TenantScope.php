<?php

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-layer half of tenant isolation: automatically constrains every
 * query on a tenant-owned model to the active tenant. PostgreSQL RLS is the
 * second, independent layer.
 *
 * When no tenant is active AND we are not in platform read-only mode, this scope
 * forces an impossible predicate so nothing leaks by accident.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->getQualifiedTenantColumn();

        if ($context->hasTenant()) {
            $builder->where($column, $context->tenantId());

            return;
        }

        // Platform read-only mode intentionally sees across tenants (RLS still
        // guards writes). Otherwise, deny everything by default.
        if (! $context->isPlatformReadonly()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
