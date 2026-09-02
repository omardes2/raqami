<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One or more expected work segments per schedule day — the split-shift model
 * (e.g. Sat 08:00–12:00 and 16:00–20:00). end_time <= start_time denotes an
 * overnight segment. The resolver reads SEGMENTS as the single source of expected
 * hours; work_schedule_days.start_time/end_time become compatibility fields kept
 * only for the backfilled default segment. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_segments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('work_schedule_day_id');
            $table->unsignedSmallInteger('sequence')->default(1); // ordering within the day
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_minutes')->nullable();
            $table->unsignedInteger('grace_minutes')->nullable();
            $table->unsignedInteger('overtime_after_minutes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_schedule_day_id')->references('id')->on('work_schedule_days')->cascadeOnDelete();
            $table->unique(['work_schedule_day_id', 'sequence']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_segments');
    }
};
