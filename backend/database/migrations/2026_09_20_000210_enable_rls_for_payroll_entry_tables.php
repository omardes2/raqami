<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the Phase-1 two-policy RLS pattern (strict tenant match for all commands
 * + read-only platform visibility, closed by default) to the Phase-2A calculation
 * tables payroll_entries and payroll_entry_lines.
 */
return new class extends Migration
{
    private array $tables = [
        'payroll_entries',
        'payroll_entry_lines',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenantGuc = "current_setting('app.tenant_id', true)";
        $platformGuc = "current_setting('app.platform_readonly', true)";

        foreach ($this->tables as $table) {
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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
