<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant attendance policy (one row per tenant). Foundational, tenant-scoped
 * settings — no commercial/legal defaults are hard-coded globally. Tenant-owned
 * (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('default_timezone', 64)->default('UTC');
            $table->unsignedInteger('default_grace_minutes')->default(15);
            $table->boolean('geofence_required')->default(false);
            $table->boolean('require_gps')->default(false);
            $table->unsignedInteger('min_gps_accuracy_meters')->nullable(); // reject reads worse than this
            $table->boolean('allow_early_check_in')->default(true);
            $table->unsignedInteger('early_check_in_window_minutes')->default(60);
            $table->boolean('allow_late_check_in')->default(true);
            $table->boolean('overtime_tracking_enabled')->default(true);
            $table->unsignedInteger('overtime_after_minutes')->default(0);
            $table->boolean('attendance_correction_enabled')->default(true);
            $table->boolean('allow_employee_correction_request')->default(true);
            $table->boolean('allow_unscheduled_work')->default(false); // off-day / no schedule
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
