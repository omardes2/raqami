<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global bank-transfer destination accounts (Super Admin configuration).
 * Only active accounts matching an invoice's currency are offered to tenants.
 * internal_notes is platform-only and never serialized to tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('label');
            $table->string('bank_name');
            $table->string('account_holder');
            $table->string('account_number');   // IBAN / account number
            $table->string('swift_code')->nullable();
            $table->char('currency', 3);
            $table->char('country_code', 2)->nullable();
            $table->text('instructions')->nullable();
            $table->text('internal_notes')->nullable(); // platform-only
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();

            $table->index(['status', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
