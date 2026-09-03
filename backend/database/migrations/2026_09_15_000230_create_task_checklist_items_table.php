<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight in-task checklist items (§19). A checklist item is a simple
 * completion tick — no assignee, no due date, no independent status (that is a
 * subtask). Completion identity is a User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id');
            $table->string('text');
            $table->boolean('is_completed')->default(false);
            $table->ulid('completed_by_user_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->index(['tenant_id', 'task_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
