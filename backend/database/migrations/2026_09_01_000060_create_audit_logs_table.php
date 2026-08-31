<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only audit trail. tenant_id is null for platform/system actions.
// UPDATE/DELETE are blocked by a DB trigger + RLS (see the RLS migration).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->string('actor_type', 20)->default('user'); // user|system|platform_admin
            $table->string('actor_label')->nullable();          // email/name snapshot
            $table->string('action')->index();                  // e.g. tenant.created
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();              // redacted; never secrets
            $table->timestamp('created_at')->nullable();        // no updated_at (append-only)

            $table->index('tenant_id');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
