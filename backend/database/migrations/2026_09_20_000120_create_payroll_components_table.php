<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant catalog of recurring compensation components (Correction G). `type` is
 * earning|deduction; `calculation_mode` is fixed|percent_of_base. No taxable /
 * statutory / formula fields in V1. Deactivation blocks NEW assignments but never
 * ends existing effective employee assignments. Tenant-owned (RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 20);              // earning|deduction
            $table->string('calculation_mode', 20);  // fixed|percent_of_base
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_components ADD CONSTRAINT payroll_components_type_chk CHECK (type IN ('earning','deduction'))");
            DB::statement("ALTER TABLE payroll_components ADD CONSTRAINT payroll_components_mode_chk CHECK (calculation_mode IN ('fixed','percent_of_base'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_components');
    }
};
