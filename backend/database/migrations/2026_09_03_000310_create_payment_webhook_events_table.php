<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-agnostic webhook ingestion log (infrastructure, NOT tenant-owned —
 * webhooks may arrive before a tenant is resolved). Uniqueness on
 * (provider, external_event_id) makes ingestion idempotent. NEVER stores raw
 * secrets or full provider payloads — only a hash and safe normalized metadata.
 * No real provider is integrated in Sprint 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 50);
            $table->string('external_event_id');
            $table->string('event_type')->nullable();
            $table->string('payload_hash', 128)->nullable();
            $table->ulid('tenant_id')->nullable(); // correlation only, no FK/RLS
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 20)->default('received'); // received|processed|failed|ignored
            $table->string('error')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
