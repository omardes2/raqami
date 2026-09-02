<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per logical work_date a request affects. Snapshots BOTH:
 *  - balance CONSUMPTION (consumption_minutes, consumption_basis, nominal),
 *  - attendance COVERAGE (coverage_minutes, coverage_intervals [UTC half-open]),
 * plus the schedule/holiday context. This freezes the exact effect at
 * submission so later schedule/holiday/policy/branch-transfer changes never
 * rewrite decided history. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('leave_request_id');
            $table->ulid('employee_id');
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('coverage_minutes')->default(0);   // expected-work minutes leave covers (attendance)
            $table->unsignedInteger('consumption_minutes')->default(0); // balance minutes consumed
            $table->string('portion', 20)->default('full_day');         // full_day|first_half|second_half
            $table->jsonb('coverage_intervals')->nullable();            // [{start_at,end_at}] UTC, half-open
            $table->string('consumption_basis', 30);
            $table->unsignedInteger('nominal_day_minutes')->nullable();
            $table->string('excluded_reason', 30)->nullable();          // holiday|non_working_day|outside_period
            $table->ulid('holiday_id')->nullable();
            $table->jsonb('holiday_snapshot')->nullable();
            $table->jsonb('schedule_snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'work_date']);
            $table->index(['tenant_id', 'leave_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_days');
    }
};
