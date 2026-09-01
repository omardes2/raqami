<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved geofence locations (circular: center + radius). Coordinates stored as
 * decimals to preserve precision (no float). Optionally tied to a branch.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('branch_id')->nullable();
            $table->string('name');
            $table->decimal('latitude', 10, 7);   // -90..90
            $table->decimal('longitude', 10, 7);  // -180..180
            $table->unsignedInteger('radius_meters');
            $table->unsignedInteger('require_accuracy_meters')->nullable();
            $table->string('status', 20)->default('active'); // active|archived
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_locations');
    }
};
