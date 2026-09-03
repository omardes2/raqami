<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task watchers (Correction F). A watcher is a notification PREFERENCE for a User
 * — it never grants task visibility. No `source` column in V1. Auto-watch is
 * creator + linked-users of assignees only (commenters are NOT auto-watched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id');
            $table->ulid('user_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->unique(['tenant_id', 'task_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
    }
};
