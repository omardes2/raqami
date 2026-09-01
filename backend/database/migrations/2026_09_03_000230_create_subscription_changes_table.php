<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorded plan changes (upgrade immediately / downgrade at period end). A
 * scheduled downgrade NEVER deletes data — it is recorded here and applied later.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subscription_id');
            $table->ulid('from_plan_id')->nullable();
            $table->ulid('to_plan_id');
            $table->string('change_type', 20);    // upgrade|downgrade
            $table->timestamp('effective_at');
            $table->string('status', 20)->default('scheduled'); // scheduled|applied|canceled
            $table->ulid('requested_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('from_plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->foreign('to_plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->index(['tenant_id', 'subscription_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
