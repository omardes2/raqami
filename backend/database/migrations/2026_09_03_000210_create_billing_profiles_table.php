<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant billing profile — the legal/tax/address identity used on invoices,
 * kept separate from the general company profile. One per tenant. Tenant-owned
 * (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('legal_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_phone', 40)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->char('preferred_currency', 3)->nullable();
            $table->text('invoice_notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
