<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assigns a schedule to an organizational scope with explicit effective dates.
 * Precedence (most specific wins): employee > team > department > branch >
 * company, resolved by ScheduleResolver. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('work_schedule_id');
            $table->string('scope_type', 20); // company|branch|department|team|employee
            $table->ulid('scope_id')->nullable(); // null for company scope
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->integer('priority')->default(0); // tie-breaker at the same level
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_schedule_id')->references('id')->on('work_schedules')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
            $table->index(['tenant_id', 'effective_from', 'effective_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_assignments');
    }
};
