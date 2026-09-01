<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-weekday configuration for a schedule (weekday 0=Sunday .. 6=Saturday).
 * Supports different hours per day and off days. end_time <= start_time denotes
 * an OVERNIGHT window (resolved deterministically at attendance time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('work_schedule_id');
            $table->unsignedTinyInteger('weekday'); // 0=Sun .. 6=Sat
            $table->boolean('is_working_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('break_minutes')->nullable();
            $table->unsignedInteger('grace_minutes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->cascadeOnDelete();
            $table->unique(['work_schedule_id', 'weekday']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
    }
};
