<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned work container (Sprint 6). A project is OPTIONAL — a task may live
 * inside a project or standalone (§4). Organizational placement uses a single
 * stable (scope_type, scope_id) pair — never three competing nullable columns.
 * Archive is orthogonal historical state (`archived_at`), NOT a status value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');       // active|on_hold|completed
            $table->string('visibility', 20)->default('scoped');   // scoped|members_only
            $table->string('scope_type', 20)->default('company');  // company|branch|department|team
            $table->ulid('scope_id')->nullable();                  // null iff scope_type=company
            $table->ulid('owner_employee_id')->nullable();
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->ulid('created_by_user_id');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);          // NULL codes never collide (Postgres)
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
            $table->index(['tenant_id', 'archived_at']);
        });

        // Bounded semantic invariants (string + CHECK, not native ENUM).
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_chk CHECK (status IN ('active','on_hold','completed'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_visibility_chk CHECK (visibility IN ('scoped','members_only'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_scope_chk CHECK ((scope_type = 'company' AND scope_id IS NULL) OR (scope_type IN ('branch','department','team') AND scope_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
