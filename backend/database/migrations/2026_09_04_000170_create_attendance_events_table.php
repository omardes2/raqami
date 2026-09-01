<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable raw punch log (append-only). Every check-in / check-out / manual /
 * correction action records exactly what the client sent and what the server
 * decided (geofence match, distance, accuracy). attendance_records holds the
 * computed daily rollup; this table is the forensic audit trail behind it.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('attendance_record_id')->nullable();
            $table->string('event_type', 20);   // see AttendanceEventType (check_in|check_out|...)
            $table->string('source', 20)->default('web'); // see AttendanceSource
            $table->timestamp('occurred_at');    // UTC, server-authoritative
            // Exactly what the client sent (facts), plus what the server decided.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->ulid('matched_location_id')->nullable();
            $table->unsignedInteger('distance_meters')->nullable(); // server-computed to matched location
            $table->boolean('inside_geofence')->nullable();          // server decision
            $table->jsonb('metadata')->nullable();                   // device/ip/user-agent context
            $table->ulid('created_by_user_id')->nullable();          // actor (self, manager, admin)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('attendance_record_id')->references('id')->on('attendance_records')->nullOnDelete();
            $table->foreign('matched_location_id')->references('id')->on('attendance_locations')->nullOnDelete();
            $table->index(['tenant_id', 'employee_id', 'occurred_at']);
            $table->index(['tenant_id', 'attendance_record_id']);
            $table->index(['tenant_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
