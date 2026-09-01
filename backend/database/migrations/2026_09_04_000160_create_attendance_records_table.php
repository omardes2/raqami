<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The computed daily attendance state (one per employee per work_date). Schedule
 * boundaries + grace/break are SNAPSHOT here at check-in so later schedule edits
 * do not retro-alter history. Timestamps are stored in UTC; work_date/timezone
 * carry the schedule-timezone context. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('work_schedule_id')->nullable();
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->timestamp('scheduled_start_at')->nullable(); // UTC snapshot
            $table->timestamp('scheduled_end_at')->nullable();   // UTC snapshot (next day if overnight)
            $table->timestamp('check_in_at')->nullable();        // UTC, server-authoritative
            $table->timestamp('check_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('grace_minutes')->default(0); // snapshot
            $table->string('status', 20)->default('present');    // see AttendanceStatus
            $table->string('source', 20)->default('web');        // see AttendanceSource
            // Geo summary (full detail lives in attendance_events)
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->boolean('check_in_inside_geofence')->nullable();
            $table->ulid('check_in_location_id')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->boolean('check_out_inside_geofence')->nullable();
            $table->ulid('check_out_location_id')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->nullOnDelete();
            // One logical daily record per employee.
            $table->unique(['tenant_id', 'employee_id', 'work_date']);
            $table->index(['tenant_id', 'work_date', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('check_in_at');
        });

        // At most one OPEN (not-yet-checked-out) record per employee.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX attendance_one_open_per_employee ON attendance_records (tenant_id, employee_id) WHERE check_out_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
