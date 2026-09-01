<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each redemption of a (platform-global) coupon by a tenant. Used to
 * enforce max_redemptions and per_tenant_limit. Tenant-owned (tenant_id + RLS)
 * so a tenant's redemption history never leaks across tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('coupon_id');
            $table->string('coupon_code', 64);
            $table->ulid('subscription_id')->nullable();
            $table->ulid('invoice_id')->nullable();
            $table->ulid('redeemed_by_user_id')->nullable();
            $table->bigInteger('discount_minor')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
            $table->index(['tenant_id', 'coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
