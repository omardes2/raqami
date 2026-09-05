<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8B Phase 1: in-app notifications (one row per recipient User).
 *
 * Tenant-owned and protected by PostgreSQL Row-Level Security (ADR-002), but with
 * a DELIBERATELY DIFFERENT policy set from every other tenant table:
 *
 *  - SELECT / UPDATE are RECIPIENT-scoped: a user sees and mutates only their own
 *    rows (tenant match AND recipient_user_id = current app.user_id). Tenant-level
 *    isolation alone is not enough — one tenant's users must not read each other's
 *    notifications.
 *  - INSERT is confined to the internal NotificationService writer context: it
 *    requires a transaction-local GUC app.notification_writer='1' (plus tenant
 *    match). A normal authenticated request never sets that GUC, so it cannot
 *    create notifications; a producer may address ANY recipient in its own tenant
 *    (recipient_user_id is NOT tied to app.user_id), which also lets queued jobs
 *    write with an empty app.user_id.
 *  - DELETE is confined to the maintenance/prune context via a transaction-local
 *    GUC app.notification_maintenance='1'. There is no user delete path.
 *  - There is intentionally NO platform_readonly SELECT policy: the platform
 *    (super admin) has no global notification inbox.
 *
 * read_at is the only user-mutable column (enforced at the application layer).
 * No updated_at, no soft deletes. subject_type/subject_id are metadata only —
 * never polymorphic FKs — so a deleted subject leaves the notification intact.
 * Retention is a later 12-month hard-delete prune (not in this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('recipient_user_id', 26);
            $table->string('type', 64);
            $table->string('subject_type', 64)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->jsonb('data')->default('{}');
            $table->string('dedupe_key', 191)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('recipient_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Inbox listing (newest-first per recipient).
            $table->index(['tenant_id', 'recipient_user_id', 'created_at'], 'notifications_inbox_idx');
            // Unread count / mark-all.
            $table->index(['tenant_id', 'recipient_user_id', 'read_at'], 'notifications_unread_idx');
            // Retention prune.
            $table->index(['tenant_id', 'created_at'], 'notifications_retention_idx');
        });

        // Deterministic dedupe: at most one row per (tenant, recipient, dedupe_key)
        // when a key is provided; NULL keys are always allowed (partial unique).
        DB::statement(
            'CREATE UNIQUE INDEX notifications_dedupe_uidx ON notifications '
            .'(tenant_id, recipient_user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
        );

        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenantGuc = "current_setting('app.tenant_id', true)";
        $userGuc = "current_setting('app.user_id', true)";
        $writerGuc = "current_setting('app.notification_writer', true)";
        $maintenanceGuc = "current_setting('app.notification_maintenance', true)";

        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notifications FORCE ROW LEVEL SECURITY');

        // Recipient may read only their own rows within the tenant.
        DB::statement(<<<SQL
            CREATE POLICY notifications_recipient_select ON notifications
                FOR SELECT
                USING (tenant_id = {$tenantGuc} AND recipient_user_id = {$userGuc})
        SQL);

        // Recipient may update only their own rows (app layer whitelists read_at).
        DB::statement(<<<SQL
            CREATE POLICY notifications_recipient_update ON notifications
                FOR UPDATE
                USING (tenant_id = {$tenantGuc} AND recipient_user_id = {$userGuc})
                WITH CHECK (tenant_id = {$tenantGuc} AND recipient_user_id = {$userGuc})
        SQL);

        // Only the NotificationService writer context may insert, for any recipient
        // in the SAME tenant (recipient_user_id NOT tied to app.user_id).
        DB::statement(<<<SQL
            CREATE POLICY notifications_writer_insert ON notifications
                FOR INSERT
                WITH CHECK (tenant_id = {$tenantGuc} AND {$writerGuc} = '1')
        SQL);

        // Only the maintenance/prune context may delete, within its tenant.
        DB::statement(<<<SQL
            CREATE POLICY notifications_maintenance_delete ON notifications
                FOR DELETE
                USING (tenant_id = {$tenantGuc} AND {$maintenanceGuc} = '1')
        SQL);

        // NOTE: intentionally NO platform_readonly policy on this table.
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
