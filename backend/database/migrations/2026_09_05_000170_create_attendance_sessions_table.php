<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An individual check-in/check-out SESSION. A work_date (attendance_record) may
 * hold several closed sessions (split shifts) but AT MOST ONE open session per
 * employee — enforced by a partial unique index. Each session is calculated
 * server-side against its schedule segment; the daily attendance_record is the
 * aggregate. Geo summary mirrors Sprint 3 (detail stays in attendance_events).
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('attendance_record_id');
            $table->ulid('employee_id');
            $table->unsignedSmallInteger('sequence')->default(1); // order within the work_date
            $table->timestamp('check_in_at');            // UTC, server-authoritative
            $table->timestamp('check_out_at')->nullable();
            // Snapshot of the matched schedule segment boundaries (UTC), like records.
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('grace_minutes')->default(0);
            $table->string('source', 20)->default('web');
            $table->boolean('is_manual')->default(false);
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->boolean('check_in_inside_geofence')->nullable();
            $table->ulid('check_in_location_id')->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->boolean('check_out_inside_geofence')->nullable();
            $table->ulid('check_out_location_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('attendance_record_id')->references('id')->on('attendance_records')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['tenant_id', 'attendance_record_id']);
            $table->index(['tenant_id', 'employee_id', 'check_in_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // At most one OPEN session per employee across all dates.
            DB::statement(
                'CREATE UNIQUE INDEX attendance_sessions_one_open_per_employee ON attendance_sessions (tenant_id, employee_id) WHERE check_out_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
