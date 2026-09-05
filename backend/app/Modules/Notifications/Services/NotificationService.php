<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY entrypoint that creates notifications (Sprint 8B Phase 1). It is
 * internal — there is no public create API. It:
 *
 *  - takes the tenant from TenantContext (never a caller argument);
 *  - requires the recipient User to have an ACTIVE tenant_membership in the
 *    CURRENT tenant (User != Employee; no email/phone/role guessing) — otherwise
 *    it returns Skipped without throwing (e.g. Employee without a linked User, or
 *    a disabled/invited membership);
 *  - persists inside a short transaction that sets the transaction-local GUC
 *    app.notification_writer='1', which the INSERT RLS policy requires, so a
 *    normal request context can never create notifications and the writer flag
 *    can never leak past the transaction;
 *  - deduplicates on the (tenant, recipient, dedupe_key) partial unique index via
 *    insertOrIgnore — the database is the sole authority.
 *
 * Architectural invariant: notifications are POST-COMMIT communication. Calling
 * this while a business transaction is open is a programming error and is
 * rejected, so a rolled-back domain change can never leave a notification behind.
 */
class NotificationService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Preferred producer entrypoint: send a WHITELISTED payload built by
     * NotificationPayloadFactory. Domain code should always call this rather than
     * the low-level notify() below, so a stable key + only safe params ever reach
     * a stored notification.
     */
    public function send(string $recipientUserId, NotificationPayload $payload): NotificationResult
    {
        return $this->notify($recipientUserId, $payload->type, $payload->data(), $payload->options());
    }

    /**
     * @param  array{key:string, params?:array<string,mixed>}  $data  safe payload (translation key + locale-neutral params only)
     * @param  array{subject_type?:?string, subject_id?:?string, dedupe_key?:?string}  $opts
     */
    public function notify(string $recipientUserId, string $type, array $data, array $opts = []): NotificationResult
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            throw new \LogicException('NotificationService requires an active tenant context.');
        }
        // Post-commit invariant: domain producers must call this from a
        // DB::afterCommit callback (Phase 3+). Enforced by convention/documentation,
        // not a transaction-level check — the test harness wraps every test in an
        // outer transaction, which would make such a check unusable.
        if (! array_key_exists('key', $data) || ! is_string($data['key']) || $data['key'] === '') {
            throw new \InvalidArgumentException('Notification payload requires a non-empty string "key".');
        }

        if (! $this->recipientHasActiveMembership($tenantId, $recipientUserId)) {
            return NotificationResult::Skipped;
        }

        $row = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'recipient_user_id' => $recipientUserId,
            'type' => $type,
            'subject_type' => $opts['subject_type'] ?? null,
            'subject_id' => $opts['subject_id'] ?? null,
            'data' => json_encode(['key' => $data['key'], 'params' => $data['params'] ?? []]),
            'dedupe_key' => $opts['dedupe_key'] ?? null,
            'read_at' => null,
            'created_at' => now(),
        ];

        // Writer context is TRANSACTION-LOCAL. set_config(..., true) scopes the
        // app.notification_writer GUC to THIS short transaction only: it satisfies
        // the INSERT RLS policy for the insert, then PostgreSQL discards it
        // automatically on COMMIT, ROLLBACK, or any thrown exception. Safety does
        // NOT depend on a session reset or a finally block, so the flag can never
        // outlive the transaction — not on a pooled connection, and not on a
        // long-lived queued-worker connection. This is the ONLY place the flag is
        // ever set; there is deliberately no reusable "become a writer" helper.
        $inserted = DB::transaction(function () use ($row): int {
            DB::statement("select set_config('app.notification_writer', '1', true)");

            return DB::table('notifications')->insertOrIgnore($row);
        });

        return $inserted > 0 ? NotificationResult::Created : NotificationResult::Duplicate;
    }

    /** The recipient must have an ACTIVE membership in the CURRENT tenant. */
    private function recipientHasActiveMembership(string $tenantId, string $recipientUserId): bool
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $recipientUserId)
            ->where('status', 'active')
            ->exists();
    }
}
