<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated monthly base salary (Corrections A/E). Compensation definition
 * is not a payroll result: a salary change inserts a NEW row; historical payroll
 * always resolves the row effective on the payroll date. Money is integer minor
 * units + explicit currency (no float). Inclusive dates: effective_from <=
 * work_date <= effective_to; NULL effective_to = open-ended. Non-overlap per
 * (tenant, employee) is enforced by service + advisory lock + DB trigger
 * (separate migration is unnecessary — the trigger lives here). Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_compensations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->char('currency', 3);
            $table->bigInteger('base_amount_minor');
            $table->bigInteger('overtime_rate_minor_per_hour')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->ulid('created_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'effective_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_base_nonneg_chk CHECK (base_amount_minor >= 0)');
            DB::statement('ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_ot_nonneg_chk CHECK (overtime_rate_minor_per_hour IS NULL OR overtime_rate_minor_per_hour >= 0)');
            DB::statement('ALTER TABLE employee_compensations ADD CONSTRAINT employee_compensations_range_chk CHECK (effective_to IS NULL OR effective_from <= effective_to)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensations');
    }
};
