<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded project-local ACL (D1). Roles are manager|member only. The canonical
 * project owner lives on projects.owner_employee_id — it is NEVER duplicated as a
 * membership row. Manager membership grants project-local task authority only,
 * never company-wide tasks.manage / projects.manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('project_id');
            $table->ulid('employee_id');
            $table->string('role', 20); // manager|member
            $table->ulid('added_by_user_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['tenant_id', 'project_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id']);
        });

        DB::statement("ALTER TABLE project_memberships ADD CONSTRAINT project_memberships_role_chk CHECK (role IN ('manager','member'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
    }
};
