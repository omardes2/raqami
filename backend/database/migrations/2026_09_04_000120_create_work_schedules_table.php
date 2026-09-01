<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reusable work schedule header (per-weekday hours live in work_schedule_days). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('code', 64);
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 20)->default('active'); // active|archived
            $table->string('description')->nullable();
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('overtime_after_minutes')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
