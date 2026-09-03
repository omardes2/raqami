<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll entry — one employee's calculated result within a run (Phase 2A). One
 * row per (tenant, run, employee). Financial totals (gross/deduction/net) and
 * currency are SERVER-DERIVED by the calculation engine only; no API sets them.
 * A run is currency-neutral, so currency lives per entry. status starts `pending`,
 * becomes `calculated` or `failed`; `finalized` exists for a later phase and is
 * never set in Phase 2A. input_snapshot + input_fingerprint make later staleness
 * detection possible. Negative-net override columns are reserved for Phase 2B and
 * stay unused now. Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('payroll_run_id');
            $table->ulid('employee_id');

            $table->char('currency', 3)->nullable(); // set only on successful calculation
            $table->string('status', 20)->default('pending'); // pending|calculated|failed|finalized

            $table->jsonb('employee_snapshot')->nullable();
            $table->jsonb('input_snapshot')->nullable();
            $table->string('input_fingerprint', 64)->nullable();

            $table->bigInteger('gross_minor')->nullable();
            $table->bigInteger('deduction_minor')->nullable();
            $table->bigInteger('net_minor')->nullable();

            $table->string('calculation_version')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->string('error_code', 50)->nullable();
            $table->jsonb('error_context')->nullable();

            // Reserved for Phase 2B (negative-net override); unused in Phase 2A.
            $table->ulid('negative_net_override_by_user_id')->nullable();
            $table->string('negative_net_override_reason')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->unique(['tenant_id', 'payroll_run_id', 'employee_id'], 'payroll_entries_run_employee_unique');
            $table->index(['tenant_id', 'payroll_run_id']);
            $table->index(['tenant_id', 'payroll_run_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_entries ADD CONSTRAINT payroll_entries_status_chk CHECK (status IN ('pending','calculated','failed','finalized'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
