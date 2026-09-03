<?php

namespace App\Modules\Payroll\Calculation;

/**
 * Deterministic SHA-256 fingerprint of the canonical calculation input snapshot.
 * Canonicalization recursively sorts OBJECT/MAP keys but PRESERVES list order —
 * the input builder already imposes an explicit, stable order on the (unordered)
 * snapshot collections, so meaningful list order is never distorted and a genuinely
 * ordered list keeps its semantics. The snapshot carries no volatile timestamps, so
 * equivalent inputs hash identically and any relevant change flips the hash.
 * Consumed by Phase-2B staleness.
 */
class PayrollInputFingerprintService
{
    /** @param array<string, mixed> $snapshot */
    public function fingerprint(array $snapshot): string
    {
        $canonical = $this->canonicalize($snapshot);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            // Preserve order (builder-canonicalized); only canonicalize each element.
            return array_map(fn ($v) => $this->canonicalize($v), $value);
        }

        ksort($value);

        return array_map(fn ($v) => $this->canonicalize($v), $value);
    }
}
