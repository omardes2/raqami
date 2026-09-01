<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applies a holiday calendar to an organizational scope. Precedence (most
 * specific first): branch > company. Department/team holidays are deferred.
 * Effective dates bound the assignment. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_calendar_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('holiday_calendar_id');
            $table->string('scope_type', 20); // company|branch
            $table->ulid('scope_id')->nullable(); // null for company scope
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('holiday_calendar_id')->references('id')->on('holiday_calendars')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_assignments');
    }
};
