<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorized temporary attendance exceptions for an employee: remote/field work,
 * allowed off-day attendance, a temporary alternate location or schedule. Created
 * by authorized managers/HR (approved directly — no employee request workflow).
 * An employee can never self-declare remote/off-day; the exception is the record
 * of that authorization. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exceptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            // remote | field | off_day_work | alternate_location | schedule_override
            $table->string('type', 30);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('attendance_mode', 20)->nullable(); // onsite|remote|field
            $table->ulid('alternate_schedule_id')->nullable();
            $table->ulid('alternate_location_id')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('active'); // active|revoked
            $table->ulid('approved_by_user_id')->nullable();
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('alternate_schedule_id')->references('id')->on('work_schedules')->nullOnDelete();
            $table->foreign('alternate_location_id')->references('id')->on('attendance_locations')->nullOnDelete();
            $table->index(['tenant_id', 'employee_id', 'effective_from']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exceptions');
    }
};
