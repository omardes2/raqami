<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationMaintenanceService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — PostgreSQL-level proof of the cutoff-bounded maintenance prune RLS
 * (migration 2026_09_26_000010). The maintenance context can read and delete
 * ONLY rows strictly older than app.notification_prune_before, only in its own
 * tenant; normal users cannot delete or use the maintenance SELECT; the platform
 * remains blocked. (Transaction-local disappearance of the GUCs is proven at
 * transaction level 0 in NotificationWriterContextTest.)
 */
class NotificationPruneRlsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedAt(Tenant $tenant, string $userId, CarbonImmutable $at): string
    {
        $id = (string) Str::ulid();
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.notification_writer', '1', false)");
        try {
            DB::table('notifications')->insert([
                'id' => $id,
                'tenant_id' => (string) $tenant->getKey(),
                'recipient_user_id' => $userId,
                'type' => 'test.event',
                'data' => json_encode(['key' => 'notifications.test.title', 'params' => []]),
                'created_at' => $at,
            ]);
        } finally {
            DB::statement("select set_config('app.notification_writer', '', false)");
            app(TenantContext::class)->clear();
        }

        return $id;
    }

    /** Run $cb with an explicit GUC set (session-scoped in-test), always reset. */
    private function withGucs(array $g, \Closure $cb): mixed
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [$g['tenant'] ?? '']);
        DB::statement("select set_config('app.user_id', ?, false)", [$g['user'] ?? '']);
        DB::statement("select set_config('app.platform_readonly', ?, false)", [$g['platform'] ?? 'off']);
        DB::statement("select set_config('app.notification_maintenance', ?, false)", [$g['maint'] ?? '']);
        DB::statement("select set_config('app.notification_prune_before', ?, false)", [$g['prune'] ?? '']);
        try {
            return $cb();
        } finally {
            foreach (['app.user_id', 'app.notification_maintenance', 'app.notification_prune_before'] as $k) {
                DB::statement("select set_config(?, '', false)", [$k]);
            }
        }
    }

    private function recipientCount(Tenant $tenant, string $userId): int
    {
        return $this->withGucs(['tenant' => (string) $tenant->getKey(), 'user' => $userId],
            fn () => (int) DB::table('notifications')->count());
    }

    public function test_1_normal_user_cannot_delete(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13));

        $deleted = $this->withGucs(['tenant' => (string) $tenant->getKey(), 'user' => (string) $owner->id, 'prune' => '2999-01-01T00:00:00Z'],
            fn () => DB::table('notifications')->where('recipient_user_id', (string) $owner->id)->delete());

        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->recipientCount($tenant, (string) $owner->id));
    }

    public function test_2_normal_user_cannot_use_maintenance_select(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $other = $this->memberWithRole($tenant, 'employee');
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13));

        // A normal user (no maintenance flag), even with a cutoff set, sees only
        // their OWN rows — never another recipient's via the maintenance policy.
        $seen = $this->withGucs(['tenant' => (string) $tenant->getKey(), 'user' => (string) $other->id, 'prune' => '2999-01-01T00:00:00Z'],
            fn () => (int) DB::table('notifications')->count());

        $this->assertSame(0, $seen);
    }

    public function test_3_and_4_maintenance_sees_only_rows_older_than_cutoff(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13)); // old
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subDays(1));    // recent

        $cutoff = CarbonImmutable::now()->subMonths(12)->toIso8601String();
        $count = $this->withGucs(['tenant' => (string) $tenant->getKey(), 'maint' => '1', 'prune' => $cutoff],
            fn () => (int) DB::table('notifications')->count());

        // Only the old row is visible; the recent one is not (test 3 + test 4).
        $this->assertSame(1, $count);
    }

    public function test_5_maintenance_deletes_only_rows_older_than_cutoff(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13)); // old
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subDays(1));    // recent

        $cutoff = CarbonImmutable::now()->subMonths(12)->toIso8601String();
        $ids = $this->withGucs(['tenant' => (string) $tenant->getKey(), 'maint' => '1', 'prune' => $cutoff],
            fn () => DB::table('notifications')->pluck('id')->all());
        $deleted = $this->withGucs(['tenant' => (string) $tenant->getKey(), 'maint' => '1', 'prune' => $cutoff],
            fn () => DB::table('notifications')->whereIn('id', $ids)->delete());

        $this->assertSame(1, $deleted);
        // The recent row survives.
        $this->assertSame(1, $this->recipientCount($tenant, (string) $owner->id));
    }

    public function test_6_cross_tenant_rows_invisible_and_undeletable(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $this->seedAt($tenantA, (string) $ownerA->id, CarbonImmutable::now()->subMonths(13));

        $cutoff = '2999-01-01T00:00:00Z';
        // Maintenance scoped to tenant B cannot see or delete tenant A's row.
        $seen = $this->withGucs(['tenant' => (string) $tenantB->getKey(), 'maint' => '1', 'prune' => $cutoff],
            fn () => (int) DB::table('notifications')->count());
        $deleted = $this->withGucs(['tenant' => (string) $tenantB->getKey(), 'maint' => '1', 'prune' => $cutoff],
            fn () => DB::table('notifications')->delete());

        $this->assertSame(0, $seen);
        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->recipientCount($tenantA, (string) $ownerA->id));
    }

    public function test_7_platform_context_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13));

        $ctx = app(TenantContext::class);
        $count = $ctx->runAsPlatform(fn () => (int) DB::table('notifications')->count());
        $this->assertSame(0, $count);

        // Even if a cutoff is present, platform (no maintenance flag) cannot delete.
        $deleted = $ctx->runAsPlatform(function () {
            DB::statement("select set_config('app.notification_prune_before', '2999-01-01T00:00:00Z', false)");
            try {
                return DB::table('notifications')->delete();
            } finally {
                DB::statement("select set_config('app.notification_prune_before', '', false)");
            }
        });
        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->recipientCount($tenant, (string) $owner->id));
    }

    public function test_9_prune_service_enforces_12_month_retention(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13));
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(11));

        $result = app(NotificationMaintenanceService::class)->prune(CarbonImmutable::now()->subMonths(12));

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $this->recipientCount($tenant, (string) $owner->id)); // the 11-month row kept
    }

    public function test_10_prune_is_idempotent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedAt($tenant, (string) $owner->id, CarbonImmutable::now()->subMonths(13));

        $first = app(NotificationMaintenanceService::class)->prune(CarbonImmutable::now()->subMonths(12));
        $second = app(NotificationMaintenanceService::class)->prune(CarbonImmutable::now()->subMonths(12));

        $this->assertSame(1, $first['deleted']);
        $this->assertSame(0, $second['deleted']);
        $this->assertSame(0, $this->recipientCount($tenant, (string) $owner->id));
    }
}
