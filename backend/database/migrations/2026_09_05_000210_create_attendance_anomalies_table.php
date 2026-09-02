<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rule-based attendance anomalies (NO AI, no fraud assertions — neutral
 * language). Examples: missing checkout, very long session, suspicious location
 * change, overlapping sessions, lateness streak, excessive corrections. A
 * dedupe_key makes detection idempotent (re-running the processor never
 * duplicates the same finding). Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('attendance_record_id')->nullable();
            $table->ulid('attendance_session_id')->nullable();
            $table->string('type', 40);          // missing_checkout|long_session|suspicious_location_change|...
            $table->string('severity', 20)->default('info'); // info|warning|high
            $table->timestamp('detected_at');
            $table->string('status', 20)->default('open'); // open|acknowledged|resolved|dismissed
            $table->jsonb('metadata')->nullable();
            $table->string('dedupe_key', 191);   // stable per (type, subject) for idempotency
            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by_user_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('attendance_record_id')->references('id')->on('attendance_records')->nullOnDelete();
            $table->foreign('attendance_session_id')->references('id')->on('attendance_sessions')->nullOnDelete();
            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_anomalies');
    }
};
