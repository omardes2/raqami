<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual payroll adjustment (Phase 2B). A tenant-owned, per-(run, employee) manual
 * earning/deduction with a MANDATORY reason. Keyed by (run, employee) rather than
 * by entry so adjustments SURVIVE recalculation (entries are regenerated; the
 * adjustment is an authoritative INPUT re-read on each calculation, feeding an
 * ADJUSTMENT line into the entry and the input snapshot/fingerprint). Single
 * money-sign truth: direction + non-negative amount_minor. Adjustments in a CLOSED
 * period are immutable (enforced by trigger). Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('payroll_run_id');
            $table->ulid('employee_id');

            $table->string('label');
            $table->string('direction', 10); // earning | deduction
            $table->bigInteger('amount_minor'); // NON-NEGATIVE magnitude
            $table->char('currency', 3);
            $table->string('reason'); // mandatory

            $table->ulid('created_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->index(['tenant_id', 'payroll_run_id', 'employee_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_direction_chk CHECK (direction IN ('earning','deduction'))");
            DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_amount_nonneg_chk CHECK (amount_minor >= 0)');

            if (config('tenancy.rls_enabled', true)) {
                $tenantGuc = "current_setting('app.tenant_id', true)";
                $platformGuc = "current_setting('app.platform_readonly', true)";
                DB::statement('ALTER TABLE payroll_adjustments ENABLE ROW LEVEL SECURITY');
                DB::statement('ALTER TABLE payroll_adjustments FORCE ROW LEVEL SECURITY');
                DB::statement("CREATE POLICY tenant_isolation ON payroll_adjustments USING (tenant_id = {$tenantGuc}) WITH CHECK (tenant_id = {$tenantGuc})");
                DB::statement("CREATE POLICY platform_readonly ON payroll_adjustments FOR SELECT USING ({$platformGuc} = 'on')");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
