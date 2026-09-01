<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS invoices. All financial totals are computed server-side (never accepted
 * from the client). invoice_number is unique per tenant and does not expose the
 * primary key. Money in integer minor units. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('subscription_id')->nullable();
            $table->string('invoice_number', 40);
            $table->string('status', 20)->default('draft'); // see InvoiceStatus
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            $table->bigInteger('amount_due_minor')->default(0);
            $table->decimal('tax_rate', 6, 3)->nullable(); // generic; no country logic
            $table->string('tax_label')->nullable();
            $table->ulid('coupon_id')->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('billing_period_start')->nullable();
            $table->timestamp('billing_period_end')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            // Invoice numbers are GLOBALLY unique across the platform (readable,
            // non-ID public reference for reconciliation/support/audit).
            $table->unique('invoice_number');
            $table->index(['tenant_id', 'status']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
