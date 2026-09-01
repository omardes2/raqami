<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend PostgreSQL Row-Level Security (ADR-002) to the tenant-linked Sprint 2
 * billing tables. Same two-policy pattern as Sprint 0/1: strict tenant match for
 * all commands + read-only visibility for the audited platform portal. Closed by
 * default when no tenant context is set.
 *
 * Platform-global billing tables (plans, plan_features, coupons, bank_accounts)
 * and infrastructure tables (payment_webhook_events, idempotency_records) are
 * intentionally NOT tenant-scoped and get no RLS.
 */
return new class extends Migration
{
    private array $tenantTables = [
        'billing_profiles',
        'subscriptions',
        'subscription_changes',
        'subscription_events',
        'invoices',
        'invoice_items',
        'payments',
        'bank_transfer_submissions',
        'coupon_redemptions',
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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("DROP POLICY IF EXISTS platform_readonly ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
