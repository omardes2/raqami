<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private task/comment attachments (§26). Reuses the Sprint 5 private-storage
 * pattern: metadata-only rows, tenant-prefixed keys, `storage_key` hidden,
 * authorized streamed/signed downloads, never a public URL, no binary in the DB.
 * A comment-scoped attachment (comment_id set) must belong to the SAME task —
 * enforced by the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('task_id');
            $table->ulid('comment_id')->nullable();
            $table->ulid('uploaded_by_user_id');
            $table->string('storage_key');
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('comment_id')->references('id')->on('task_comments')->cascadeOnDelete();
            $table->index(['tenant_id', 'task_id']);
            $table->index(['tenant_id', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
