<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend PostgreSQL Row-Level Security (ADR-002) to the Sprint 5 leave tables.
 * Standard tables use the same two-policy pattern as Sprint 0-4 (strict tenant
 * match for all commands + read-only platform visibility). The ledger
 * (leave_balance_transactions) is APPEND-ONLY: it gets separate SELECT + INSERT
 * policies (no UPDATE/DELETE policy, so RLS denies those) plus a trigger that
 * raises — the same guarantee as audit_logs. Closed by default with no context.
 */
return new class extends Migration
{
    /** Standard tenant-owned leave tables. */
    private array $tenantTables = [
        'leave_types',
        'leave_policies',
        'leave_policy_assignments',
        'leave_entitlement_periods',
        'leave_balances',
        'leave_requests',
        'leave_request_days',
        'leave_request_approvals',
        'leave_request_attachments',
        'leave_settings',
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

        // leave_balance_transactions: append-only ledger. Tenant-scoped reads (or
        // platform read), inserts for the active tenant only. No UPDATE/DELETE
        // policy exists, so RLS denies those; a trigger makes it explicit.
        DB::statement('ALTER TABLE leave_balance_transactions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE leave_balance_transactions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<SQL
            CREATE POLICY leave_ledger_select ON leave_balance_transactions
                FOR SELECT
                USING (tenant_id = {$tenantGuc} OR {$platformGuc} = 'on')
        SQL);
        DB::statement(<<<SQL
            CREATE POLICY leave_ledger_insert ON leave_balance_transactions
                FOR INSERT
                WITH CHECK (tenant_id = {$tenantGuc})
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_leave_ledger_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'leave_balance_transactions is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER leave_ledger_no_mutation
                BEFORE UPDATE OR DELETE ON leave_balance_transactions
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_leave_ledger_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS leave_ledger_no_mutation ON leave_balance_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_leave_ledger_mutation()');

        foreach (['leave_ledger_select', 'leave_ledger_insert'] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON leave_balance_transactions");
        }
        DB::statement('ALTER TABLE leave_balance_transactions DISABLE ROW LEVEL SECURITY');

        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
