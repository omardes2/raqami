<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime approval workflow. calculated_minutes is server-derived (raw) and
 * kept SEPARATE from approved_minutes — the distinction matters for future
 * payroll. A reviewer cannot approve more than calculated without an explicit
 * override permission; the requester/employee can never self-approve. One row
 * per attendance_record. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('attendance_record_id');
            $table->ulid('employee_id');
            $table->date('work_date');
            $table->unsignedInteger('calculated_minutes')->default(0); // raw, server-derived
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->ulid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('attendance_record_id')->references('id')->on('attendance_records')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['tenant_id', 'attendance_record_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_approvals');
    }
};
