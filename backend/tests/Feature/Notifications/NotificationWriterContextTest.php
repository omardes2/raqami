<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Services\NotificationResult;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B Phase 3 hardening — proves the app.notification_writer GUC is
 * TRANSACTION-LOCAL: set_config(..., true) scopes it to the NotificationService
 * insert transaction, and PostgreSQL discards it on COMMIT, ROLLBACK, or an
 * exception. Safety therefore does NOT depend on a session reset, so the writer
 * capability can never outlive the insert — not on a pooled connection, and not
 * on a long-lived queued-worker connection that is reused across jobs.
 *
 * This guarantee is invisible under RefreshDatabase: its wrapping transaction
 * turns the service's DB::transaction into a SAVEPOINT, so the transaction-local
 * GUC attaches to the outer transaction and appears to persist. So this class
 * runs at transaction level 0 (no RefreshDatabase), COMMITs real rows, and
 * cleans them up afterwards.
 */
class NotificationWriterContextTest extends TestCase
{
    use InteractsWithTenancy;

    /** @var array<int, string> */
    private array $committedTenantIds = [];

    /** Read the writer GUC on the current (shared) connection. */
    private function writerFlag(): string
    {
        return (string) (DB::selectOne("select current_setting('app.notification_writer', true) as w")->w ?? '');
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

    public function test_writer_guc_clears_on_commit_and_leaves_no_capability(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->committedTenantIds[] = (string) $tenant->id;
        $recipient = $this->memberWithRole($tenant, 'employee');
        $tid = (string) $tenant->id;

        // Precondition: this connection holds no writer capability.
        $this->assertSame('', $this->writerFlag());

        // A real service call really COMMITs its transaction-local writer context.
        $result = $this->withinTenant($tenant, fn () => app(NotificationService::class)->notify(
            (string) $recipient->getKey(), 'test.event', ['key' => 'notifications.test.title']
        ));
        $this->assertSame(NotificationResult::Created, $result);

        // COMMIT discarded the flag — it did NOT leak onto this connection (the
        // exact hazard for a reused queued-worker connection).
        $this->assertSame('', $this->writerFlag(), 'writer GUC must not survive the committed insert transaction');

        // Consequently a normal (no-writer) INSERT on this same connection is denied.
        $denied = false;
        $this->withinTenant($tenant, function () use (&$denied, $tid, $recipient) {
            try {
                DB::table('notifications')->insert($this->row($tid, (string) $recipient->getKey()));
            } catch (\Throwable) {
                $denied = true;
            }
        });
        $this->assertTrue($denied, 'a no-writer context must be denied INSERT after a committed service call');
    }

    public function test_writer_guc_is_discarded_on_rollback(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->committedTenantIds[] = (string) $tenant->id;

        $this->assertSame('', $this->writerFlag());

        // A transaction that enters the writer context then throws must roll back
        // AND discard the transaction-local flag.
        $threw = false;
        try {
            $this->withinTenant($tenant, fn () => DB::transaction(function () {
                DB::statement("select set_config('app.notification_writer', '1', true)");
                throw new \RuntimeException('boom');
            }));
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertSame('', $this->writerFlag(), 'writer GUC must be discarded when its transaction rolls back');
    }

    public function test_duplicate_insert_still_clears_writer_guc(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->committedTenantIds[] = (string) $tenant->id;
        $recipient = $this->memberWithRole($tenant, 'employee');

        $opts = ['dedupe_key' => 'writer-ctx-dupe'];
        $first = $this->withinTenant($tenant, fn () => app(NotificationService::class)->notify(
            (string) $recipient->getKey(), 'test.event', ['key' => 'notifications.test.title'], $opts
        ));
        $second = $this->withinTenant($tenant, fn () => app(NotificationService::class)->notify(
            (string) $recipient->getKey(), 'test.event', ['key' => 'notifications.test.title'], $opts
        ));

        $this->assertSame(NotificationResult::Created, $first);
        $this->assertSame(NotificationResult::Duplicate, $second);
        // Even on the dedupe (insertOrIgnore no-op) path, the committed transaction
        // discards the writer flag.
        $this->assertSame('', $this->writerFlag(), 'writer GUC must clear on the duplicate/no-op insert path too');
    }

    public function test_maintenance_and_cutoff_gucs_are_transaction_local(): void
    {
        // Committed transaction: both GUCs must be discarded on COMMIT.
        DB::transaction(function () {
            DB::statement("select set_config('app.notification_maintenance', '1', true)");
            DB::statement("select set_config('app.notification_prune_before', '2999-01-01T00:00:00Z', true)");
        });
        $after = DB::selectOne("select current_setting('app.notification_maintenance', true) m, current_setting('app.notification_prune_before', true) p");
        $this->assertSame('', (string) ($after->m ?? ''), 'maintenance GUC must not survive commit');
        $this->assertSame('', (string) ($after->p ?? ''), 'prune cutoff GUC must not survive commit');

        // Rolled-back transaction: both GUCs must also be gone.
        try {
            DB::transaction(function () {
                DB::statement("select set_config('app.notification_maintenance', '1', true)");
                DB::statement("select set_config('app.notification_prune_before', '2999-01-01T00:00:00Z', true)");
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $after = DB::selectOne("select current_setting('app.notification_maintenance', true) m, current_setting('app.notification_prune_before', true) p");
        $this->assertSame('', (string) ($after->m ?? ''), 'maintenance GUC must not survive rollback');
        $this->assertSame('', (string) ($after->p ?? ''), 'prune cutoff GUC must not survive rollback');
    }

    protected function tearDown(): void
    {
        if ($this->committedTenantIds !== [] && DB::getDriverName() === 'pgsql') {
            // Notifications carry FORCE RLS with a maintenance-only DELETE policy,
            // so the tenant-delete cascade would be blocked. Remove this tenant's
            // notifications first under a maintenance context; the later cascade
            // then finds nothing to delete.
            foreach ($this->committedTenantIds as $tid) {
                DB::statement("select set_config('app.tenant_id', ?, false)", [$tid]);
                DB::statement("select set_config('app.platform_readonly', 'off', false)");
                DB::statement("select set_config('app.notification_maintenance', '1', false)");
                try {
                    DB::table('notifications')->delete();
                } finally {
                    DB::statement("select set_config('app.notification_maintenance', '', false)");
                }
            }
            app(TenantContext::class)->clear();
            DB::table('tenants')->whereIn('id', $this->committedTenantIds)->delete();
            $this->committedTenantIds = [];
        }

        parent::tearDown();
    }
}
