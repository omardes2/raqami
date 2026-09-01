<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-GLOBAL invoice-number sequence (one row per year). NOT tenant-owned
 * (no tenant_id, no RLS) so invoice numbers are globally unique and
 * concurrency-safe via an atomic INSERT ... ON CONFLICT ... RETURNING. Replaces
 * the earlier per-tenant billing_counters, which made numbers unique only within
 * a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->integer('year')->unique();
            $table->bigInteger('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
