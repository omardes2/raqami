<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The task. A task is EITHER standalone (project_id NULL + a stable
 * (scope_type, scope_id)) OR inside a project (project_id set + scope columns
 * NULL, inheriting the project scope/visibility). The CHECK enforces this
 * scope-source exclusivity so scope can never be an editable copy of the
 * project's. board_rank orders cards only within a project Kanban column and is
 * NULL for standalone tasks and subtasks (§23). Optimistic `version`;
 * creator-scoped idempotent create via client_request_id + fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('project_id')->nullable();
            $table->ulid('parent_task_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->ulid('status_id');
            $table->string('priority', 20)->default('normal');  // low|normal|high|urgent
            $table->string('scope_type', 20)->nullable();        // standalone only
            $table->ulid('scope_id')->nullable();
            $table->string('due_type', 20)->default('none');     // none|date|datetime
            $table->date('due_on')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('due_timezone', 64)->nullable();
            $table->date('start_on')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->bigInteger('board_rank')->nullable();
            $table->ulid('created_by_user_id');
            $table->ulid('created_by_employee_id')->nullable();
            $table->string('client_request_id', 128)->nullable();
            $table->string('client_request_hash', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('status_id')->references('id')->on('task_statuses');
            $table->index(['tenant_id', 'status_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'priority']);
            $table->index(['tenant_id', 'due_on']);
            $table->index(['tenant_id', 'due_at']);
            $table->index(['tenant_id', 'parent_task_id']);
            $table->index(['tenant_id', 'archived_at']);
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
            $table->index(['project_id', 'status_id', 'board_rank']);
        });

        // Self-referencing FK added after table creation (avoids Postgres
        // "no unique constraint" during inline create).
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('parent_task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_chk CHECK (priority IN ('low','normal','high','urgent'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_due_type_chk CHECK (due_type IN ('none','date','datetime'))");
        // Scope-source exclusivity: project task (scope NULL) XOR standalone (scope set).
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_scope_source_chk CHECK ((project_id IS NOT NULL AND scope_type IS NULL AND scope_id IS NULL) OR (project_id IS NULL AND scope_type IS NOT NULL AND ((scope_type = 'company' AND scope_id IS NULL) OR (scope_type IN ('branch','department','team') AND scope_id IS NOT NULL))))");
        // Due-field consistency per due_type (§22).
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_due_shape_chk CHECK ((due_type = 'none' AND due_on IS NULL AND due_at IS NULL AND due_timezone IS NULL) OR (due_type = 'date' AND due_on IS NOT NULL AND due_at IS NULL AND due_timezone IS NOT NULL) OR (due_type = 'datetime' AND due_at IS NOT NULL AND due_on IS NULL AND due_timezone IS NOT NULL))");
        // board_rank only for project tasks (standalone/subtask ranks handled by service; NULL enforced for standalone).
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_board_rank_scope_chk CHECK (board_rank IS NULL OR project_id IS NOT NULL)');
        // Creator-scoped idempotency: at most one row per (tenant, creator, client_request_id).
        DB::statement('CREATE UNIQUE INDEX tasks_idempotency_unique ON tasks (tenant_id, created_by_user_id, client_request_id) WHERE client_request_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
