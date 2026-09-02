<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The leave request aggregate. No server draft (D5): lifecycle begins at
 * `pending` on submit. requested_consumption_minutes drives the balance;
 * requested_coverage_minutes drives attendance. `version` guards concurrent
 * transitions. Policy/consumption state is snapshotted here and on request_days.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('leave_type_id');
            $table->ulid('leave_policy_id')->nullable();
            $table->ulid('entitlement_period_id');
            $table->string('request_kind', 20)->default('full_day'); // full_day|first_half|second_half
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('requested_consumption_minutes')->default(0);
            $table->unsignedInteger('requested_coverage_minutes')->default(0);
            $table->string('status', 30)->default('pending');
            $table->string('consumption_basis', 30); // snapshot
            $table->text('reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->nullOnDelete();
            $table->foreign('entitlement_period_id')->references('id')->on('leave_entitlement_periods')->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
