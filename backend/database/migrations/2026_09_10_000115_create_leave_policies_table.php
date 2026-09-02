<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable rule set bound to a leave type (kept separate from the type).
 * Canonical unit is MINUTES throughout. consumption_basis + nominal_day_minutes
 * (D7) decide how a day converts to consumed balance; count_holidays /
 * count_non_working_days require the nominal basis (validated in the service).
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('leave_type_id');
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            // Entitlement period basis
            $table->string('period_basis', 30)->default('calendar_year'); // calendar_year|employment_anniversary|custom_tenant_year

            // Entitlement
            $table->string('entitlement_method', 20)->default('none'); // none|fixed|accrual
            $table->unsignedInteger('entitlement_minutes')->default(0);

            // Accrual
            $table->string('accrual_frequency', 20)->default('none'); // none|monthly|annual
            $table->unsignedInteger('accrual_minutes')->default(0);
            $table->boolean('proration_enabled')->default(false); // D4: OFF by default

            // Balance
            $table->unsignedInteger('max_balance_minutes')->nullable();
            $table->boolean('allow_negative_balance')->default(false);
            $table->unsignedInteger('max_negative_minutes')->nullable();

            // Carry forward / expiry
            $table->boolean('carry_forward_enabled')->default(false);
            $table->unsignedInteger('carry_forward_max_minutes')->nullable();
            $table->unsignedInteger('carry_forward_expiry_days')->nullable();

            // Consumption basis (D7)
            $table->string('consumption_basis', 30)->default('scheduled_minutes');
            $table->unsignedInteger('nominal_day_minutes')->nullable();
            $table->boolean('count_holidays')->default(false);
            $table->boolean('count_non_working_days')->default(false);

            // Request rules
            $table->boolean('allow_half_day')->default(true);
            $table->unsignedInteger('minimum_request_minutes')->nullable();
            $table->unsignedInteger('maximum_request_minutes')->nullable();
            $table->unsignedInteger('minimum_notice_days')->nullable();
            $table->unsignedInteger('maximum_advance_booking_days')->nullable();
            $table->boolean('requires_attachment')->default(false);

            // Withdrawal / cancellation hooks
            $table->boolean('allow_withdrawal')->default(true);
            $table->boolean('allow_cancellation_request')->default(true);

            // Approval
            $table->string('approval_flow', 30)->default('manager'); // none|manager|hr|manager_then_hr

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->index(['tenant_id', 'leave_type_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};
