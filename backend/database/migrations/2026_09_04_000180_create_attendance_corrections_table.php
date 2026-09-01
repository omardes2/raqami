<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled correction workflow for an existing attendance_record. An employee
 * (or manager) REQUESTS a change; an authorized reviewer approves or rejects.
 * No self-approval (enforced in the service layer). The prior values are
 * snapshot in old_values so an approved correction is fully auditable/reversible.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('attendance_record_id');
            $table->ulid('employee_id');
            $table->ulid('requested_by_user_id');
            $table->timestamp('requested_check_in_at')->nullable(); // UTC
            $table->timestamp('requested_check_out_at')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->ulid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->jsonb('old_values')->nullable();  // snapshot of the record before applying
            $table->jsonb('new_values')->nullable();  // snapshot of the applied values
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('attendance_record_id')->references('id')->on('attendance_records')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'attendance_record_id']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
