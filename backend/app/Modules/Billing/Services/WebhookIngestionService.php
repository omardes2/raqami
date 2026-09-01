<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider-agnostic webhook ingestion foundation (spec §29). NO real provider is
 * integrated in Sprint 2 and there is no public webhook route — this establishes
 * the idempotent seam a future card provider plugs into. Uniqueness on
 * (provider, external_event_id) means a replayed event is ingested at most once.
 * Never stores secrets or raw payloads — only a hash and safe metadata.
 */
class WebhookIngestionService
{
    /**
     * Idempotently record an inbound provider event. Returns false if the event
     * (provider, external_event_id) was already ingested.
     */
    public function ingest(string $provider, string $externalEventId, ?string $eventType = null, ?string $payloadHash = null, ?string $tenantId = null): bool
    {
        $inserted = DB::table('payment_webhook_events')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'provider' => $provider,
            'external_event_id' => $externalEventId,
            'event_type' => $eventType,
            'payload_hash' => $payloadHash,
            'tenant_id' => $tenantId,
            'received_at' => now(),
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0;
    }

    public function markProcessed(string $provider, string $externalEventId): void
    {
        PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('external_event_id', $externalEventId)
            ->update(['status' => 'processed', 'processed_at' => now()]);
    }
}
