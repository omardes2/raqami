<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GDPR-ready foundation (ADR-013). Concept-level, tenant-owned structures only —
// no full compliance workflows are built in Sprint 0.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('subject_type', 20)->default('user'); // user|employee
            $table->ulid('subject_id')->nullable();
            $table->string('consent_type');
            $table->boolean('granted')->default(false);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('data_export_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('requested_by')->nullable();
            $table->string('subject_type', 20)->default('user');
            $table->ulid('subject_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|ready|delivered|failed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('requested_by')->nullable();
            $table->string('subject_type', 20)->default('user');
            $table->ulid('subject_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|processing|completed|rejected
            $table->string('reason')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('data_class');
            $table->unsignedInteger('retention_days');
            $table->string('action', 20)->default('delete'); // delete|anonymize
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('data_deletion_requests');
        Schema::dropIfExists('data_export_requests');
        Schema::dropIfExists('consent_records');
    }
};
