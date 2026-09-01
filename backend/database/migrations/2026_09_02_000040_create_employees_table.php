<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Employee HR record — a SEPARATE entity from User (auth identity).
// An employee may exist with no user_id (no login). department_id FK is added
// later (departments already exist, but kept with the cross-FK migration for
// clarity alongside the reverse manager FK).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('employee_number', 64);

            // Identity
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('display_name')->nullable();

            // Organization
            $table->ulid('branch_id')->nullable();
            $table->ulid('department_id')->nullable();
            $table->ulid('job_title_id')->nullable();
            $table->ulid('direct_manager_employee_id')->nullable();

            // Employment
            $table->string('employment_status', 20)->default('active'); // active|onboarding|probation|suspended|on_leave|terminated|archived
            $table->string('employment_type', 20)->default('full_time'); // full_time|part_time|contract|temporary|internship|freelance
            $table->date('hire_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('termination_reason')->nullable();

            // System relationship (optional link to a login account)
            $table->ulid('user_id')->nullable();

            // Contact
            $table->string('work_email')->nullable();
            $table->string('personal_email')->nullable();   // sensitive
            $table->string('work_phone', 40)->nullable();
            $table->string('mobile_phone', 40)->nullable();  // sensitive

            // Basic profile (mostly sensitive)
            $table->date('date_of_birth')->nullable();        // sensitive
            $table->string('gender', 20)->nullable();
            $table->char('nationality', 2)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('address_line')->nullable();       // sensitive
            $table->string('city')->nullable();

            // Metadata
            $table->text('notes')->nullable();                // sensitive, permission-gated
            $table->string('status', 20)->default('active');  // record status: active|archived
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('job_title_id')->references('id')->on('job_titles')->nullOnDelete();
            // Self-referential FK (direct_manager_employee_id) added in the
            // deferred cross-entity FK migration.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'employment_status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'job_title_id']);
            $table->index('direct_manager_employee_id');
            $table->index('user_id');
            $table->unique(['tenant_id', 'employee_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
