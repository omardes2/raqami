<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenant-owned departments; hierarchical (self parent). manager_employee_id FK
// is added later (employees table is created afterwards).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('branch_id')->nullable(); // null => company-wide
            $table->ulid('parent_department_id')->nullable();
            $table->string('name');
            $table->string('code', 64);
            $table->string('description')->nullable();
            $table->ulid('manager_employee_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            // Self-referential FK (parent_department_id) added in the deferred
            // cross-entity FK migration to avoid PostgreSQL create-order issues.
            $table->index('tenant_id');
            $table->index(['tenant_id', 'branch_id']);
            $table->index('parent_department_id');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
