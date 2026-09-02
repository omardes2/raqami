<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only, user-facing task/project activity timeline (D4) — SEPARATE from
 * audit_logs (security audit). event_type is an application-level string (no
 * native ENUM). metadata carries IDs / enum transitions / safe labels / non-
 * sensitive scalar old-new values ONLY — never comment bodies, file bytes,
 * storage keys, or sensitive text. Requires at least one target (task or
 * project). RLS append-only enforcement is added in the enable-RLS migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activity_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id')->nullable();
            $table->ulid('project_id')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->string('event_type', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['tenant_id', 'task_id', 'created_at']);
            $table->index(['tenant_id', 'project_id', 'created_at']);
        });

        // At least one target must be present.
        DB::statement('ALTER TABLE task_activity_events ADD CONSTRAINT task_activity_target_chk CHECK (task_id IS NOT NULL OR project_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activity_events');
    }
};
