<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @mentions inside a comment (§25). References a User (account/notification
 * identity). The server validates the mentioned user is same-tenant AND already
 * has legitimate task visibility — a mention NEVER grants visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comment_mentions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('comment_id');
            $table->ulid('mentioned_user_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('comment_id')->references('id')->on('task_comments')->cascadeOnDelete();
            $table->unique(['tenant_id', 'comment_id', 'mentioned_user_id']);
            $table->index(['tenant_id', 'mentioned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_mentions');
    }
};
