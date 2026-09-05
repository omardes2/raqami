<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 9 (AI) — append-only operational ledger of AI usage per tenant.
 *
 * Records only SAFE operational metadata for cost/observability and entitlement
 * enforcement: tenant, actor user, provider/model, the feature, input/output
 * units (tokens), an estimated provider cost, a status, and a timestamp. It
 * NEVER stores prompt/response content or any employee/payroll/private data.
 *
 * `estimated_cost_micro` is provider cost in micro-USD (integer, single
 * currency) — operational metadata about our own AI spend, NOT tenant financial
 * data, so it is unrelated to payroll money and never mixed with tenant
 * currencies.
 *
 * Tenant-owned and FORCE RLS (ADR-002), append-only like other ledgers: tenant
 * (or platform read-only) SELECT + tenant INSERT, no UPDATE/DELETE policy, and a
 * trigger that makes the append-only guarantee explicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('feature', 64);
            $table->unsignedInteger('input_units')->default(0);
            $table->unsignedInteger('output_units')->default(0);
            $table->bigInteger('estimated_cost_micro')->nullable(); // provider cost, micro-USD
            $table->string('status', 32)->default('ok'); // ok | error | blocked
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'created_at'], 'ai_usage_tenant_created_idx');
            $table->index(['tenant_id', 'feature', 'created_at'], 'ai_usage_feature_idx');
        });

        if (DB::getDriverName() !== 'pgsql' || ! config('tenancy.rls_enabled', true)) {
            return;
        }

        $tenantGuc = "current_setting('app.tenant_id', true)";
        $platformGuc = "current_setting('app.platform_readonly', true)";

        DB::statement('ALTER TABLE ai_usage_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ai_usage_events FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            CREATE POLICY ai_usage_select ON ai_usage_events
                FOR SELECT
                USING (tenant_id = {$tenantGuc} OR {$platformGuc} = 'on')
        SQL);
        DB::statement(<<<SQL
            CREATE POLICY ai_usage_insert ON ai_usage_events
                FOR INSERT
                WITH CHECK (tenant_id = {$tenantGuc})
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION raqmi_prevent_ai_usage_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ai_usage_events is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ai_usage_no_mutation
                BEFORE UPDATE OR DELETE ON ai_usage_events
                FOR EACH ROW EXECUTE FUNCTION raqmi_prevent_ai_usage_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ai_usage_no_mutation ON ai_usage_events');
            DB::unprepared('DROP FUNCTION IF EXISTS raqmi_prevent_ai_usage_mutation()');
        }
        Schema::dropIfExists('ai_usage_events');
    }
};
