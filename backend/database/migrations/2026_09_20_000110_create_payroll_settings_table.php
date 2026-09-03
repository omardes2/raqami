<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant payroll configuration (Correction D — minimal: only settings that
 * are actually used in V1). Proration basis, rounding mode, currency, absence/
 * late deduction, overtime multiplier and payslip numbering are all fixed by the
 * Sprint 7 architecture or deferred, so they are NOT settings. Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('payroll_timezone', 64)->default('UTC'); // IANA
            $table->boolean('overtime_pay_enabled')->default(false);
            $table->boolean('require_four_eyes')->default(false);
            $table->boolean('allow_self_payroll')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id'); // one settings row per tenant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
