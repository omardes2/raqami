<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll run — the calculation/finalization envelope for a period (§14,
 * Corrections B/C/N). A run is CURRENCY-NEUTRAL: it stores NO currency and NO
 * scalar gross/deduction/net totals (one run may contain employees in different
 * currencies; run summaries are grouped by currency from entries in a later
 * phase). Exactly one non-cancelled run per period (partial unique index). No
 * run_type / payment fields. Calculation & finalization behaviour arrive later;
 * Phase 1 provides the lifecycle skeleton only. Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('payroll_period_id');
            $table->string('status', 30)->default('draft');
            $table->string('calculation_version')->nullable();
            $table->ulid('calculation_requested_by_user_id')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->ulid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('finalized_by_user_id')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->ulid('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payroll_period_id')->references('id')->on('payroll_periods')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_status_chk CHECK (status IN ('draft','calculating','calculation_failed','calculated','approved','finalized','cancelled'))");
            // At most one active (non-cancelled) run per period.
            DB::statement("CREATE UNIQUE INDEX payroll_runs_one_active_per_period ON payroll_runs (payroll_period_id) WHERE status <> 'cancelled'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
