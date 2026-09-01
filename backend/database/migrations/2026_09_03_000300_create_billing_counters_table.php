<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant monotonic counters used to generate human-readable, non-sequential
 * billing references (e.g. invoice numbers) without exposing DB ids. Incremented
 * atomically (INSERT ... ON CONFLICT ... RETURNING) so concurrent requests never
 * collide. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_counters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('key', 64);
            $table->bigInteger('value')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_counters');
    }
};
