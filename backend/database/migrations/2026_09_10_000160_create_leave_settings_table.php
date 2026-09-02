<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant leave defaults (one row per tenant). display_day_minutes is a
 * DISPLAY-ONLY conversion for rendering minutes as "days" in the UI — the
 * canonical backend unit stays integer minutes and entitlement consumption
 * never depends on it. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('default_period_basis', 30)->default('calendar_year');
            $table->unsignedTinyInteger('leave_year_start_month')->default(1); // for custom_tenant_year
            $table->unsignedTinyInteger('leave_year_start_day')->default(1);
            $table->string('default_approval_flow', 30)->default('manager');
            $table->boolean('allow_withdrawal')->default(true);
            $table->boolean('allow_cancellation_request')->default(true);
            $table->unsignedSmallInteger('display_day_minutes')->default(480); // display-only (8h)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_settings');
    }
};
