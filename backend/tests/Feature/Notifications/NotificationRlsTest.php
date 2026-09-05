<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B Phase 1 — proves the notifications RLS policies at the PostgreSQL
 * level (non-superuser `raqmi` role, FORCE RLS) rather than via the Eloquent
 * scope: recipient-scoped SELECT/UPDATE, writer-only INSERT, maintenance-only
 * DELETE, no platform access, and no writer-GUC leakage across a service call.
 */
class NotificationRlsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Set the GUC quartet on the session, run $cb, then always clear the write flags. */
    private function withGucs(array $g, \Closure $cb): mixed
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [$g['tenant'] ?? '']);
        DB::statement("select set_config('app.user_id', ?, false)", [$g['user'] ?? '']);
        DB::statement("select set_config('app.platform_readonly', ?, false)", [$g['platform'] ?? 'off']);
        DB::statement("select set_config('app.notification_writer', ?, false)", [$g['writer'] ?? '']);
        DB::statement("select set_config('app.notification_maintenance', ?, false)", [$g['maint'] ?? '']);
        try {
            return $cb();
        } finally {
            DB::statement("select set_config('app.notification_writer', '', false)");
            DB::statement("select set_config('app.notification_maintenance', '', false)");
        }
    }

    /** Attempt a raw insert in a savepoint; return true if RLS/DB rejected it. */
    private function insertRejected(array $row): bool
    {
        try {
            DB::transaction(fn () => DB::table('notifications')->insert($row));

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    private function row(string $tenantId, string $recipientId): array
    {
        return [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'recipient_user_id' => $recipientId,
            'type' => 'test.event',
            'data' => json_encode(['key' => 'notifications.test.title', 'params' => []]),
            'created_at' => now(),
        ];
    }

    private function seedNotes(Tenant $tenant, User $recipient): void
    {
        $this->withinTenant($tenant, fn () => app(NotificationService::class)->notify(
            (string) $recipient->getKey(), 'test.event', ['key' => 'notifications.test.title']
        ));
    }

    public function test_recipient_select_and_update_isolation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $b = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a);
        $this->seedNotes($tenant, $b);
        $tid = (string) $tenant->getKey();

        // A sees only A's row.
        $seen = $this->withGucs(['tenant' => $tid, 'user' => (string) $a->getKey()],
            fn () => DB::table('notifications')->pluck('recipient_user_id')->all());
        $this->assertSame([(string) $a->getKey()], $seen);

        // B's row id (read under B).
        $bId = $this->withGucs(['tenant' => $tid, 'user' => (string) $b->getKey()],
            fn () => DB::table('notifications')->value('id'));

        // A cannot update B's row (recipient UPDATE policy → 0 rows).
        $affected = $this->withGucs(['tenant' => $tid, 'user' => (string) $a->getKey()],
            fn () => DB::table('notifications')->where('id', $bId)->update(['read_at' => now()]));
        $this->assertSame(0, $affected);

        // B's row is still unread.
        $readAt = $this->withGucs(['tenant' => $tid, 'user' => (string) $b->getKey()],
            fn () => DB::table('notifications')->where('id', $bId)->value('read_at'));
        $this->assertNull($readAt);
    }

    public function test_insert_requires_writer_context(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $tid = (string) $tenant->getKey();

        // Normal authed context (tenant+user, NO writer) → INSERT denied.
        $denied = $this->withGucs(['tenant' => $tid, 'user' => (string) $a->getKey()],
            fn () => $this->insertRejected($this->row($tid, (string) $a->getKey())));
        $this->assertTrue($denied, 'normal context must not insert notifications');

        // Writer context → INSERT allowed.
        $ok = $this->withGucs(['tenant' => $tid, 'writer' => '1'],
            fn () => DB::table('notifications')->insert($this->row($tid, (string) $a->getKey())));
        $this->assertTrue($ok);

        // Queued-style: writer set, app.user_id EMPTY → still allowed.
        $ok2 = $this->withGucs(['tenant' => $tid, 'user' => '', 'writer' => '1'],
            fn () => DB::table('notifications')->insert($this->row($tid, (string) $a->getKey())));
        $this->assertTrue($ok2);
    }

    public function test_writer_cannot_insert_cross_tenant(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $b = $this->memberWithRole($tenantB, 'employee');

        // Writer context for tenant A tries to insert a row stamped tenant B → denied.
        $denied = $this->withGucs(['tenant' => (string) $tenantA->getKey(), 'writer' => '1'],
            fn () => $this->insertRejected($this->row((string) $tenantB->getKey(), (string) $b->getKey())));
        $this->assertTrue($denied);
    }

    public function test_delete_requires_maintenance_context(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a);
        $tid = (string) $tenant->getKey();

        // Normal user cannot delete (no maintenance GUC) → 0 rows.
        $affected = $this->withGucs(['tenant' => $tid, 'user' => (string) $a->getKey()],
            fn () => DB::table('notifications')->delete());
        $this->assertSame(0, $affected);

        // Maintenance context in the same tenant → delete allowed.
        $deleted = $this->withGucs(['tenant' => $tid, 'maint' => '1'],
            fn () => DB::table('notifications')->delete());
        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    public function test_maintenance_cannot_delete_cross_tenant(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenantA, 'employee');
        $this->seedNotes($tenantA, $a);

        // Maintenance context scoped to tenant B cannot touch tenant A rows.
        $deleted = $this->withGucs(['tenant' => (string) $tenantB->getKey(), 'maint' => '1'],
            fn () => DB::table('notifications')->delete());
        $this->assertSame(0, $deleted);

        $remaining = $this->withGucs(['tenant' => (string) $tenantA->getKey(), 'user' => (string) $a->getKey()],
            fn () => DB::table('notifications')->count());
        $this->assertSame(1, $remaining);
    }

    public function test_platform_context_has_no_notification_access(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a);
        $tid = (string) $tenant->getKey();

        $ctx = app(TenantContext::class);
        // SELECT under platform read-only → no inbox rows.
        $count = $ctx->runAsPlatform(fn () => DB::table('notifications')->count());
        $this->assertSame(0, $count);
        // INSERT under platform → denied.
        $denied = $ctx->runAsPlatform(fn () => $this->insertRejected($this->row($tid, (string) $a->getKey())));
        $this->assertTrue($denied);
    }

    public function test_writer_guc_does_not_leak_after_service_call(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a); // service enters + exits writer context

        // The writer flag must be reset immediately after the service call.
        $writer = DB::selectOne("select current_setting('app.notification_writer', true) as w")->w;
        $this->assertTrue($writer === '' || $writer === null, 'writer flag must not leak past the service call');

        // And a normal (no-writer) context is consequently denied INSERT.
        $tid = (string) $tenant->getKey();
        $denied = $this->withGucs(['tenant' => $tid, 'user' => (string) $a->getKey()],
            fn () => $this->insertRejected($this->row($tid, (string) $a->getKey())));
        $this->assertTrue($denied, 'writer flag must not leak past the service call');
    }

    public function test_model_create_and_delete_denied_in_normal_context(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a);

        // Eloquent create without writer context → RLS rejects (no accidental bypass).
        $ctx = app(TenantContext::class);
        $ctx->setUser((string) $a->getKey());
        $ctx->setTenant($tenant);
        try {
            $createDenied = false;
            try {
                DB::transaction(fn () => Notification::create([
                    'recipient_user_id' => (string) $a->getKey(),
                    'type' => 'test.event',
                    'data' => ['key' => 'notifications.test.title'],
                ]));
            } catch (\Throwable) {
                $createDenied = true;
            }
            $this->assertTrue($createDenied, 'Notification::create must be denied without writer context');

            // Eloquent delete without maintenance context → 0 rows.
            $deleted = Notification::query()->delete();
            $this->assertSame(0, $deleted);
        } finally {
            $ctx->clear();
        }
    }
}
