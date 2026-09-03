<?php

namespace App\Modules\Payroll\Calculation;

/**
 * Deterministic SHA-256 fingerprint of the canonical calculation input snapshot.
 * Canonicalization sorts object keys recursively and orders every list by the
 * canonical JSON of its elements, so the hash depends only on the SET of
 * calculation-relevant facts — never on key order, list order, or volatile
 * timestamps (the snapshot deliberately carries none). Equivalent inputs hash
 * identically; any relevant change flips the hash. Consumed by Phase-2B staleness.
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
            $items = array_map(fn ($v) => $this->canonicalize($v), $value);
            usort($items, fn ($a, $b) => json_encode($a) <=> json_encode($b));

            return $items;
        }

        ksort($value);

        return array_map(fn ($v) => $this->canonicalize($v), $value);
    }
}
