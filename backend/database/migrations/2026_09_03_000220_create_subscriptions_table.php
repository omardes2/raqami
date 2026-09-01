<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's single primary subscription (belongs to the TENANT, never a user).
 * Plan changes mutate this row; lifecycle status is a constrained string driven
 * by the SubscriptionStatus enum. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('plan_id');
            $table->string('status', 20)->default('trialing');    // see SubscriptionStatus
            $table->string('billing_interval', 10)->default('monthly'); // monthly|annual
            $table->char('currency', 3)->default('USD');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            // One primary subscription per tenant (V1).
            $table->unique('tenant_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
