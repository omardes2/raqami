<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment records (card/bank_transfer/cash/manual). Success is applied
 * transactionally to the invoice. idempotency_key guards against duplicate
 * financial application. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('invoice_id')->nullable();
            $table->ulid('subscription_id')->nullable();
            $table->string('method', 20);   // card|bank_transfer|cash|manual
            $table->string('provider')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending'); // see PaymentStatus
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by_platform_admin_id')->nullable();
            $table->ulid('recorded_by_user_id')->nullable();
            $table->string('failed_reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index('invoice_id');
            $table->index('provider_payment_id');
            $table->unique('idempotency_key'); // NULLs allowed multiple times in PG
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
