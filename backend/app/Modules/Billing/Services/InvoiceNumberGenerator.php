<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates GLOBALLY-unique, human-readable invoice numbers:
 *   INV-{YYYY}-{000001}
 * The per-year counter lives in the platform-global invoice_number_sequences
 * table (no tenant_id / no RLS) and is incremented with an atomic
 * INSERT ... ON CONFLICT ... RETURNING, so concurrent requests across ANY
 * tenants never produce a duplicate number (spec §3). The value does not expose
 * a database primary key, and invoices.invoice_number carries a global unique
 * constraint as the final backstop.
 */
class InvoiceNumberGenerator
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO invoice_number_sequences (id, year, value, created_at, updated_at)
                VALUES (?, ?, 1, now(), now())
                ON CONFLICT (year)
                DO UPDATE SET value = invoice_number_sequences.value + 1, updated_at = now()
                RETURNING value
            SQL,
            [(string) Str::ulid(), $year],
        );

        return sprintf('INV-%d-%06d', $year, (int) $row->value);
    }
}
