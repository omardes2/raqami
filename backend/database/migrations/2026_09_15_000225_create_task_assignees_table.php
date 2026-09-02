<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task assignees (Correction D). Multiple assignees; AT MOST ONE primary — no
 * separate assignment_role column. Assignees are Employee identities (not User).
 * Zero-or-one primary is DB-enforced by a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id');
            $table->ulid('employee_id');
            $table->boolean('is_primary')->default(false);
            $table->ulid('assigned_by_user_id');
            $table->timestampTz('assigned_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['tenant_id', 'task_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id']);
        });

        // Exactly zero-or-one primary responsible employee per task.
        DB::statement('CREATE UNIQUE INDEX task_assignees_one_primary ON task_assignees (tenant_id, task_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};
