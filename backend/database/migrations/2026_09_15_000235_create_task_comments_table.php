<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task comments (Correction G). Author identity is a User (account identity);
 * employee_id is a nullable snapshot. Soft-delete only (history preserved).
 * Optimistic `version` guards edit/delete; creator-scoped idempotent create via
 * client_request_id + fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id');
            $table->ulid('user_id');
            $table->ulid('employee_id')->nullable();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->string('client_request_id', 128)->nullable();
            $table->string('client_request_hash', 64)->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->index(['tenant_id', 'task_id', 'created_at']);
        });

        DB::statement('CREATE UNIQUE INDEX task_comments_idempotency_unique ON task_comments (tenant_id, user_id, client_request_id) WHERE client_request_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
