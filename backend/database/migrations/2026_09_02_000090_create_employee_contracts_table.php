<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employment contract foundation. NO compensation/payroll logic (ADR-014).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->string('contract_number', 64);
            $table->string('contract_type', 40)->default('permanent'); // permanent|fixed_term|contractor|internship
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->string('status', 20)->default('active'); // draft|active|ended|terminated|archived
            $table->string('notes')->nullable();
            $table->ulid('document_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('employee_documents')->nullOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'employee_id']);
            $table->unique(['tenant_id', 'contract_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
