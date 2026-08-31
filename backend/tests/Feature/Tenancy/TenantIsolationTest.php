<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedTwoTenants(): array
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'Alpha']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'Beta']);

        // A second member in each tenant.
        $memberA = $this->makeUser();
        $memberB = $this->makeUser();

        $mA = $this->withinTenant($tenantA, fn () => TenantMembership::create([
            'user_id' => $memberA->id, 'status' => 'active',
        ]));
        $mB = $this->withinTenant($tenantB, fn () => TenantMembership::create([
            'user_id' => $memberB->id, 'status' => 'active',
        ]));

        return compact('tenantA', 'tenantB', 'mA', 'mB');
    }

    public function test_application_scope_hides_other_tenant_rows(): void
    {
        ['tenantA' => $tenantA, 'mB' => $mB] = $this->seedTwoTenants();

        $this->withinTenant($tenantA, function () use ($mB) {
            // Tenant A sees its own 2 memberships (owner + member), not B's.
            $this->assertSame(2, TenantMembership::count());
            // IDOR: fetching B's row by id yields nothing.
            $this->assertNull(TenantMembership::find($mB->id));
        });
    }

    public function test_cannot_update_or_delete_other_tenant_rows(): void
    {
        ['tenantA' => $tenantA, 'tenantB' => $tenantB, 'mB' => $mB] = $this->seedTwoTenants();

        $this->withinTenant($tenantA, function () use ($mB) {
            $updated = TenantMembership::where('id', $mB->id)->update(['status' => 'disabled']);
            $this->assertSame(0, $updated);

            $deleted = TenantMembership::where('id', $mB->id)->delete();
            $this->assertSame(0, $deleted);
        });

        // B's row is untouched — verified from within tenant B's own context.
        $this->withinTenant($tenantB, function () use ($mB) {
            $this->assertSame('active', TenantMembership::find($mB->id)?->status);
        });
    }

    public function test_rls_blocks_even_when_application_scope_is_removed(): void
    {
        ['tenantA' => $tenantA, 'mB' => $mB] = $this->seedTwoTenants();

        $this->withinTenant($tenantA, function () use ($mB) {
            // Deliberately strip the app-layer scope: the DATABASE (RLS) must
            // still refuse the other tenant's row.
            $found = TenantMembership::withoutGlobalScope(TenantScope::class)
                ->whereKey($mB->id)
                ->exists();
            $this->assertFalse($found, 'RLS should block cross-tenant row even without the app scope.');

            // Raw query (no Eloquent scope at all) is also blocked by RLS.
            $raw = DB::table('tenant_memberships')->where('id', $mB->id)->exists();
            $this->assertFalse($raw, 'RLS should block a raw cross-tenant query.');
        });
    }

    public function test_no_tenant_context_is_closed_by_default(): void
    {
        $this->seedTwoTenants();
        app(TenantContext::class)->clear();

        // With no active tenant, both layers expose nothing.
        $this->assertSame(0, TenantMembership::count());
        $this->assertSame(0, DB::table('tenant_memberships')->count());
    }

    public function test_belongs_to_tenant_blocks_forging_a_foreign_tenant_id(): void
    {
        ['tenantA' => $tenantA, 'tenantB' => $tenantB] = $this->seedTwoTenants();
        $user = $this->makeUser();

        $this->withinTenant($tenantA, function () use ($tenantB, $user) {
            $this->expectException(\RuntimeException::class);
            // Try to stamp a row for tenant B while acting as tenant A.
            TenantMembership::create([
                'tenant_id' => $tenantB->id,
                'user_id' => $user->id,
                'status' => 'active',
            ]);
        });
    }
}
