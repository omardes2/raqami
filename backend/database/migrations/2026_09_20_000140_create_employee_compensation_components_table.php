<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated recurring components assigned to an employee (Correction H).
 * Exactly one of (fixed_amount_minor, rate_bps) is set: `fixed` carries an
 * amount + currency (currency must match the payroll entry currency at
 * calculation); `percent_of_base` carries integer basis points (500 = 5%,
 * 1250 = 12.5%) and no currency. rate_bps is capped as an overflow guard.
 * Non-overlap per (tenant, employee, component) is enforced by service +
 * advisory lock + DB trigger. Tenant-owned (RLS).
 */
return new class extends Migration
{
    /** Overflow/abuse guard ceiling for basis points (= 10,000%). */
    private const MAX_RATE_BPS = 1000000;

    public function up(): void
    {
        Schema::create('employee_compensation_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('payroll_component_id');
            $table->bigInteger('fixed_amount_minor')->nullable();
            $table->integer('rate_bps')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->ulid('created_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('payroll_component_id')->references('id')->on('payroll_components')->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'payroll_component_id', 'effective_from'], 'ecc_tenant_emp_comp_eff_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $max = self::MAX_RATE_BPS;
            // Exactly one value shape (fixed amount XOR basis points).
            DB::statement('ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_value_shape_chk CHECK (num_nonnulls(fixed_amount_minor, rate_bps) = 1)');
            // Fixed requires a currency; percent must not carry one.
            DB::statement('ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_fixed_currency_chk CHECK (fixed_amount_minor IS NULL OR currency IS NOT NULL)');
            DB::statement('ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_percent_currency_chk CHECK (rate_bps IS NULL OR currency IS NULL)');
            DB::statement('ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_fixed_nonneg_chk CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)');
            DB::statement("ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_bps_range_chk CHECK (rate_bps IS NULL OR (rate_bps > 0 AND rate_bps <= {$max}))");
            DB::statement('ALTER TABLE employee_compensation_components ADD CONSTRAINT ecc_range_chk CHECK (effective_to IS NULL OR effective_from <= effective_to)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensation_components');
    }
};
