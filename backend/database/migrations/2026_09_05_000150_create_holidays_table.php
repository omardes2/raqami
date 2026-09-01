<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual holiday entries in a calendar. Multi-day holidays use end_date
 * (inclusive). is_paid is a foundation flag only (no payroll here). Tenant-owned
 * (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('holiday_calendar_id');
            $table->string('name');
            $table->date('date');                 // start (inclusive)
            $table->date('end_date')->nullable(); // inclusive; null = single day
            $table->string('type', 20)->default('public'); // public|company|branch_specific|custom
            $table->boolean('is_paid')->nullable(); // foundation only
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('holiday_calendar_id')->references('id')->on('holiday_calendars')->cascadeOnDelete();
            $table->index(['tenant_id', 'holiday_calendar_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
