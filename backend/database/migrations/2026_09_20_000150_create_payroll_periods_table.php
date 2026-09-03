<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit historical payroll periods (Correction A). V1 regular periods are FULL
 * CALENDAR MONTHS only (first→last day of the same month), enforced by the
 * service; one period per tenant/month via unique(tenant_id, period_start). The
 * period snapshots the tenant payroll timezone at creation so a later settings
 * change never rewrites history. No pay_date / payment fields. Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('label');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('timezone', 64);
            $table->string('status', 20)->default('open'); // open|closed
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'period_start']);
            $table->index(['tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_status_chk CHECK (status IN ('open','closed'))");
            DB::statement('ALTER TABLE payroll_periods ADD CONSTRAINT payroll_periods_range_chk CHECK (period_start <= period_end)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
