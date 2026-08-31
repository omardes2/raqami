<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Seeds the default/system roles for a tenant and wires each to its Sprint 0
 * permissions. Must run inside the tenant's context (RLS/global scope) so the
 * rows are stamped for the correct tenant.
 */
class RoleProvisioner
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, Role> slug => Role */
    public function provisionDefaults(Tenant $tenant): array
    {
        return $this->context->runAs($tenant, function () {
            $permissionIds = Permission::query()->pluck('id', 'key');
            $roles = [];

            foreach (PermissionCatalog::ROLES as $slug => $definition) {
                $role = Role::query()->firstOrCreate(
                    ['tenant_id' => $this->context->tenantId(), 'slug' => $slug],
                    ['name' => $definition['name'], 'is_system' => true],
                );

                $keys = PermissionCatalog::permissionsForRole($slug);
                $ids = collect($keys)
                    ->map(fn (string $key) => $permissionIds[$key] ?? null)
                    ->filter()
                    ->values()
                    ->all();

                $role->permissions()->sync($this->withTenantPivot($ids));
                $roles[$slug] = $role;
            }

            return $roles;
        });
    }

    /** role_permission carries tenant_id (denormalized) for RLS. */
    private function withTenantPivot(array $permissionIds): array
    {
        $tenantId = $this->context->tenantId();

        return collect($permissionIds)
            ->mapWithKeys(fn (string $id) => [$id => ['tenant_id' => $tenantId]])
            ->all();
    }
}
