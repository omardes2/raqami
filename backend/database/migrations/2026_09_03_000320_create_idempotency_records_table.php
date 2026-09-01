<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic idempotency guard for financial operations (bank-transfer approval,
 * manual payment, payment application, webhook processing). A unique
 * (scope, idempotency_key) makes a duplicate execution a no-op. Infrastructure
 * table, not tenant-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 60);
            $table->string('idempotency_key');
            $table->string('status', 20)->default('completed');
            $table->string('result_reference')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
