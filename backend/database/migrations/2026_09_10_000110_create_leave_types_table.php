<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant catalog of leave kinds (annual/sick/unpaid/…). `category` is a generic
 * grouping only — it never encodes an entitlement amount or a legal rule (those
 * live in leave_policies). `paid_classification` is a future-Payroll hint; no
 * money is computed in Sprint 5. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 20)->default('other'); // annual|sick|unpaid|...
            $table->string('status', 20)->default('active');   // active|archived
            $table->string('paid_classification', 20)->nullable(); // paid|unpaid|null (classification only)
            $table->boolean('requires_attachment')->default(false);
            $table->unsignedInteger('attachment_required_after_minutes')->nullable();
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_hourly')->default(false); // future-ready; not implemented in V1
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
