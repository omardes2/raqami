<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balance PROJECTION (cache + lock row) per (employee, leave_type, period). NOT
 * authoritative history — it is transactionally maintained from, and fully
 * rebuildable from, leave_balance_transactions. `available_minutes` and
 * `adjusted_minutes` may be negative (override / negative adjustments), so they
 * are signed. `version` supports optimistic concurrency. Tenant-owned + RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('leave_type_id');
            $table->ulid('entitlement_period_id');
            $table->unsignedInteger('granted_minutes')->default(0);
            $table->unsignedInteger('accrued_minutes')->default(0);
            $table->unsignedInteger('carried_minutes')->default(0);
            $table->integer('adjusted_minutes')->default(0);   // signed
            $table->unsignedInteger('used_minutes')->default(0);
            $table->unsignedInteger('reserved_minutes')->default(0);
            $table->unsignedInteger('expired_minutes')->default(0);
            $table->integer('available_minutes')->default(0);  // signed (can be negative with override)
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('entitlement_period_id')->references('id')->on('leave_entitlement_periods')->cascadeOnDelete();
            $table->unique(['tenant_id', 'employee_id', 'leave_type_id', 'entitlement_period_id'], 'leave_balances_unique_period');
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
