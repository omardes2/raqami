<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-oriented COMMERCIAL timeline for a subscription (trial_started,
 * activated, plan_changed, renewed, suspended, ...). Distinct from the security
 * audit_logs. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subscription_id');
            $table->string('event_type', 60);
            $table->string('actor_type', 20)->nullable(); // user|platform_admin|system
            $table->ulid('actor_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->index(['tenant_id', 'subscription_id']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
