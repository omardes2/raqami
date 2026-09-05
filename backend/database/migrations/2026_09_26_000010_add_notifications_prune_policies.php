<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8B — narrow, approved RLS evolution enabling the 12-month retention
 * prune WITHOUT giving the maintenance context unrestricted read.
 *
 * The maintenance context gains a CUTOFF-BOUNDED SELECT, and its DELETE is
 * tightened to the SAME predicate, so maintenance can only ever see and delete
 * notifications strictly older than a transaction-local cutoff:
 *
 *   tenant_id = app.tenant_id
 *   AND app.notification_maintenance = '1'
 *   AND created_at < app.notification_prune_before   (NULL/unset ⇒ nothing)
 *
 * NULLIF guards the cutoff: when app.notification_prune_before is unset (''),
 * the cast yields NULL and `created_at < NULL` is NULL (false), so a maintenance
 * context without an explicit cutoff can neither read nor delete ANY row — and
 * can never touch recent rows. The recipient SELECT/UPDATE and writer INSERT
 * policies are unchanged; there is still no platform policy; normal users still
 * cannot delete; cross-tenant remains impossible (tenant match).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenant = "current_setting('app.tenant_id', true)";
        $maintenance = "current_setting('app.notification_maintenance', true)";
        $cutoff = "NULLIF(current_setting('app.notification_prune_before', true), '')::timestamptz";
        $predicate = "tenant_id = {$tenant} AND {$maintenance} = '1' AND created_at < {$cutoff}";

        // Cutoff-bounded maintenance SELECT (new): only rows older than the cutoff.
        DB::statement(<<<SQL
            CREATE POLICY notifications_maintenance_select ON notifications
                FOR SELECT
                USING ({$predicate})
        SQL);

        // Tighten the maintenance DELETE to the SAME cutoff-bounded predicate.
        DB::statement('DROP POLICY notifications_maintenance_delete ON notifications');
        DB::statement(<<<SQL
            CREATE POLICY notifications_maintenance_delete ON notifications
                FOR DELETE
                USING ({$predicate})
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenant = "current_setting('app.tenant_id', true)";
        $maintenance = "current_setting('app.notification_maintenance', true)";

        DB::statement('DROP POLICY IF EXISTS notifications_maintenance_select ON notifications');
        DB::statement('DROP POLICY IF EXISTS notifications_maintenance_delete ON notifications');

        // Restore the original unbounded maintenance DELETE policy.
        DB::statement(<<<SQL
            CREATE POLICY notifications_maintenance_delete ON notifications
                FOR DELETE
                USING (tenant_id = {$tenant} AND {$maintenance} = '1')
        SQL);
    }
};
