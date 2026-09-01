<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global coupons / promo codes. Codes are stored NORMALIZED (uppercase,
 * trimmed) so the unique index enforces case-insensitive uniqueness. Money is in
 * integer minor units; percentage is a whole-number percent (0–100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 64)->unique();          // stored normalized (uppercase)
            $table->string('name');
            $table->string('type', 20);                    // percentage|fixed_amount
            $table->unsignedSmallInteger('percentage')->nullable(); // for percentage type
            $table->bigInteger('amount_minor')->nullable();         // for fixed_amount type
            $table->char('currency', 3)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('per_tenant_limit')->nullable();
            // Global redemption tally kept on the (platform-global) coupon so
            // max_redemptions is enforceable without cross-tenant reads.
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->ulid('applicable_plan_id')->nullable();
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();

            $table->foreign('applicable_plan_id')->references('id')->on('plans')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
