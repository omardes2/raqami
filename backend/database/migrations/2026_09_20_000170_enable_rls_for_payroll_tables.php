<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend PostgreSQL Row-Level Security (ADR-002) to the Phase-1 payroll tables,
 * using the same two-policy pattern as Sprint 0-6 (strict tenant match for all
 * commands + read-only platform visibility, closed by default). Structured so
 * Phase 2 can append tables 7-9 cleanly.
 *
 * Also installs the effective-range overlap backstops (Correction F — no
 * btree_gist): BEFORE INSERT/UPDATE triggers that reject an overlapping
 * effective range for the same (tenant, employee) compensation, or
 * (tenant, employee, component) recurring component. Combined with the
 * per-key advisory lock + service check, overlaps cannot be created under a race.
 */
return new class extends Migration
{
    private array $tenantTables = [
        'payroll_settings',
        'payroll_components',
        'employee_compensations',
        'employee_compensation_components',
        'payroll_periods',
        'payroll_runs',
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

        // Overlap backstop: employee_compensations per (tenant, employee).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_compensation_overlap()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM employee_compensations e
                    WHERE e.tenant_id = NEW.tenant_id
                      AND e.employee_id = NEW.employee_id
                      AND e.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(e.effective_to, DATE '9999-12-31')
                      AND e.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensations effective range for employee %', NEW.employee_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER employee_compensations_no_overlap
                BEFORE INSERT OR UPDATE ON employee_compensations
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_compensation_overlap();
        SQL);

        // Overlap backstop: employee_compensation_components per (tenant, employee, component).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_comp_component_overlap()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM employee_compensation_components c
                    WHERE c.tenant_id = NEW.tenant_id
                      AND c.employee_id = NEW.employee_id
                      AND c.payroll_component_id = NEW.payroll_component_id
                      AND c.id <> NEW.id
                      AND NEW.effective_from <= COALESCE(c.effective_to, DATE '9999-12-31')
                      AND c.effective_from <= COALESCE(NEW.effective_to, DATE '9999-12-31')
                ) THEN
                    RAISE EXCEPTION 'overlapping employee_compensation_components range for employee % component %', NEW.employee_id, NEW.payroll_component_id
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER employee_compensation_components_no_overlap
                BEFORE INSERT OR UPDATE ON employee_compensation_components
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_comp_component_overlap();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS employee_compensations_no_overlap ON employee_compensations');
        DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_compensation_overlap()');
        DB::unprepared('DROP TRIGGER IF EXISTS employee_compensation_components_no_overlap ON employee_compensation_components');
        DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_comp_component_overlap()');

        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
