<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Teams — distinct from departments. team_lead_employee_id FK added later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('branch_id')->nullable();
            $table->ulid('department_id')->nullable();
            $table->string('name');
            $table->string('code', 64);
            $table->string('description')->nullable();
            $table->ulid('team_lead_employee_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'department_id']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
