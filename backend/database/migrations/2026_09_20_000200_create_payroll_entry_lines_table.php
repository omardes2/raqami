<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll entry line — one explainable earning/deduction magnitude on an entry
 * (Phase 2A). Single money-sign truth: `direction` (earning|deduction) plus a
 * NON-NEGATIVE `amount_minor`. There is never a signed amount. Lines are generated
 * artifacts, regenerated wholesale on (re)calculation — no updated_at. Traceable
 * via (source_type, source_id). No arbitrary formula JSON, no sensitive payload.
 * Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_entry_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('payroll_entry_id');

            $table->string('line_code', 40);   // BASE_SALARY | COMPONENT_EARNING | ...
            $table->string('line_type', 40);   // catalogued line type
            $table->string('direction', 10);   // earning | deduction

            $table->string('source_type', 60);
            $table->ulid('source_id')->nullable();

            $table->string('label_snapshot');

            $table->unsignedInteger('quantity_minutes')->nullable();
            $table->bigInteger('rate_minor_per_hour')->nullable();
            $table->integer('rate_bps')->nullable();

            $table->bigInteger('amount_minor'); // NON-NEGATIVE magnitude

            $table->jsonb('metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payroll_entry_id')->references('id')->on('payroll_entries')->cascadeOnDelete();

            $table->index(['tenant_id', 'payroll_entry_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_entry_lines ADD CONSTRAINT payroll_entry_lines_direction_chk CHECK (direction IN ('earning','deduction'))");
            DB::statement('ALTER TABLE payroll_entry_lines ADD CONSTRAINT payroll_entry_lines_amount_nonneg_chk CHECK (amount_minor >= 0)');
            DB::statement('ALTER TABLE payroll_entry_lines ADD CONSTRAINT payroll_entry_lines_bps_nonneg_chk CHECK (rate_bps IS NULL OR rate_bps >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_lines');
    }
};
