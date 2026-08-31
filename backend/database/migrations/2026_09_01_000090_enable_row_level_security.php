<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL Row-Level Security (RLS) — defense-in-depth for tenant isolation
 * (ADR-002). Even if application-layer scoping is bypassed, the database itself
 * refuses cross-tenant rows.
 *
 * How it works:
 *  - Each request/job sets the GUC `app.tenant_id` (see TenantContext). Policies
 *    only expose rows whose tenant_id matches that GUC.
 *  - With no context set, current_setting(..., true) is NULL, so ZERO rows match
 *    — closed by default.
 *  - FORCE ROW LEVEL SECURITY makes even the table owner obey policies.
 *  - The audited Super Admin portal may set `app.platform_readonly = 'on'` to
 *    gain read-only cross-tenant visibility. Writes NEVER bypass tenant scope.
 *
 * Requires the application DB role to be a non-superuser without BYPASSRLS.
 */
return new class extends Migration
{
    /** Standard tenant-owned tables: strict tenant match for all commands. */
    private array $tenantTables = [
        'tenant_memberships',
        'roles',
        'role_permission',
        'role_assignments',
        'consent_records',
        'data_export_requests',
        'data_deletion_requests',
        'retention_policies',
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

            // Writes + reads for the active tenant only.
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation ON {$table}
                    USING (tenant_id = {$tenantGuc})
                    WITH CHECK (tenant_id = {$tenantGuc})
            SQL);

            // Additional read-only visibility for the audited platform portal.
            DB::statement(<<<SQL
                CREATE POLICY platform_readonly ON {$table}
                    FOR SELECT
                    USING ({$platformGuc} = 'on')
            SQL);
        }

        // A logged-in user may read THEIR OWN memberships (across the tenants
        // they belong to) before choosing an active tenant. This never exposes
        // other users' rows, so cross-tenant isolation is preserved.
        DB::statement(<<<'SQL'
            CREATE POLICY own_membership_readonly ON tenant_memberships
                FOR SELECT
                USING (user_id = current_setting('app.user_id', true))
        SQL);

        // audit_logs: append-only. Reads are tenant-scoped (or platform read).
        // Inserts must be for the active tenant, a null-tenant platform/system
        // row, or made in platform mode. No UPDATE/DELETE policy exists, so RLS
        // denies those entirely; a trigger raises to make it explicit.
        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');
        DB::statement(<<<SQL
            CREATE POLICY audit_select ON audit_logs
                FOR SELECT
                USING (
                    tenant_id = {$tenantGuc}
                    OR {$platformGuc} = 'on'
                )
        SQL);
        DB::statement(<<<SQL
            CREATE POLICY audit_insert ON audit_logs
                FOR INSERT
                WITH CHECK (
                    tenant_id = {$tenantGuc}
                    OR (tenant_id IS NULL)
                    OR {$platformGuc} = 'on'
                )
        SQL);

        // Hard guarantee that historical audit entries cannot be mutated.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_audit_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_mutation
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_audit_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_mutation ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_audit_mutation()');

        foreach (['audit_select', 'audit_insert'] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON audit_logs");
        }
        DB::statement('ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY');

        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
