<?php

namespace App\Modules\Billing\Services;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates human-readable, non-sequential-looking invoice numbers per tenant:
 *   INV-{YYYY}-{000001}
 * The per-(tenant, year) counter is incremented with an atomic
 * INSERT ... ON CONFLICT ... RETURNING, so concurrent requests never produce a
 * duplicate number (spec §34). Numbers do not expose DB ids and are unique per
 * tenant (enforced by the invoices unique(tenant_id, invoice_number) index).
 */
class InvoiceNumberGenerator
{
    public function __construct(private readonly TenantContext $context) {}

    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $tenantId = $this->context->tenantId();
        $key = "invoice:{$year}";

        // Atomic upsert-and-increment; returns the new counter value. Runs inside
        // the tenant context so the RLS WITH CHECK (tenant_id = GUC) passes.
        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO billing_counters (id, tenant_id, key, value, created_at, updated_at)
                VALUES (?, ?, ?, 1, now(), now())
                ON CONFLICT (tenant_id, key)
                DO UPDATE SET value = billing_counters.value + 1, updated_at = now()
                RETURNING value
            SQL,
            [(string) Str::ulid(), $tenantId, $key],
        );

        $sequence = (int) $row->value;

        return sprintf('INV-%d-%06d', $year, $sequence);
    }
}
