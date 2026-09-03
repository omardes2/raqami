<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend PostgreSQL Row-Level Security (ADR-002) to the Sprint 6 task tables.
 * Standard tables use the same two-policy pattern as Sprint 0-5 (strict tenant
 * match for all commands + read-only platform visibility). task_activity_events
 * is APPEND-ONLY: SELECT + INSERT policies (no UPDATE/DELETE policy, so RLS
 * denies those) plus a trigger that raises — the same guarantee as audit_logs /
 * leave_balance_transactions. Closed by default with no tenant context.
 */
return new class extends Migration
{
    /** Standard tenant-owned task tables. */
    private array $tenantTables = [
        'projects',
        'project_memberships',
        'task_statuses',
        'tasks',
        'task_assignees',
        'task_checklist_items',
        'task_comments',
        'task_comment_mentions',
        'task_watchers',
        'task_attachments',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenantGuc = "current_setting('app.tenant_id', true)";
        $platformGuc = "current_setting('app.platform_readonly', true)";

        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = {$tenantGuc})
                    WITH CHECK (tenant_id = {$tenantGuc})
            SQL);

            DB::statement(<<<SQL
                CREATE POLICY platform_readonly ON {$table}
                    FOR SELECT
                    USING ({$platformGuc} = 'on')
            SQL);
        }

        // task_activity_events: append-only activity timeline.
        DB::statement('ALTER TABLE task_activity_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE task_activity_events FORCE ROW LEVEL SECURITY');
        DB::statement(<<<SQL
            CREATE POLICY task_activity_select ON task_activity_events
                FOR SELECT
                USING (tenant_id = {$tenantGuc} OR {$platformGuc} = 'on')
        SQL);
        DB::statement(<<<SQL
            CREATE POLICY task_activity_insert ON task_activity_events
                FOR INSERT
                WITH CHECK (tenant_id = {$tenantGuc})
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_task_activity_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'task_activity_events is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER task_activity_no_mutation
                BEFORE UPDATE OR DELETE ON task_activity_events
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_task_activity_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS task_activity_no_mutation ON task_activity_events');
        DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_task_activity_mutation()');

        foreach (['task_activity_events', ...$this->tenantTables] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("DROP POLICY IF EXISTS task_activity_select ON {$table}");
            DB::statement("DROP POLICY IF EXISTS task_activity_insert ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
