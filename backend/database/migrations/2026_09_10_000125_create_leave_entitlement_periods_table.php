<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per (employee, leave_type) accounting window. The basis (calendar /
 * anniversary / custom tenant year) is policy/tenant-driven — never assumed to
 * be Jan 1. The ledger and balance projection are period-scoped.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlement_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('leave_type_id');
            $table->ulid('leave_policy_id')->nullable();
            $table->string('period_type', 30); // calendar_year|employment_anniversary|custom_tenant_year
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open'); // open|closed
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->unique(['tenant_id', 'employee_id', 'leave_type_id', 'starts_on']);
            $table->index(['tenant_id', 'employee_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlement_periods');
    }
};
