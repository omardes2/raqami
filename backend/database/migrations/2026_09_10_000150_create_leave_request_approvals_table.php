<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshotted approval steps, built at submission so a later employee transfer
 * never silently reroutes a pending workflow. `approver_user_id` is null for an
 * hr_pool step (any holder of required_permission within the snapshotted scope
 * may act). `purpose` distinguishes the original approval from a cancellation
 * approval (D3). Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('leave_request_id');
            $table->unsignedSmallInteger('step_order')->default(1);
            $table->string('purpose', 20)->default('approval'); // approval|cancellation
            $table->string('approver_type', 30); // direct_manager|department_manager|team_lead|hr_pool
            $table->ulid('approver_user_id')->nullable();
            $table->string('required_permission', 64)->nullable();
            $table->string('scope_type', 20)->nullable();
            $table->ulid('scope_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|skipped|cancelled
            $table->ulid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->index(['tenant_id', 'leave_request_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_approvals');
    }
};
