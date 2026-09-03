<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Services\PayrollSettingsService;

/**
 * Recomputes the CURRENT canonical fingerprint for an entry's employee + period and
 * compares it to the stored one. Equivalent authoritative inputs → equal fingerprint
 * → not stale; any relevant change (or inputs that no longer build) → stale. Phase 2A
 * does not finalize, but this is the authority Phase 2B finalization will consult.
 */
class PayrollStaleInputService
{
    public function __construct(
        private readonly PayrollInputBuilder $builder,
        private readonly PayrollInputFingerprintService $fingerprints,
        private readonly PayrollSettingsService $settings,
    ) {}

    public function currentFingerprint(PayrollEntry $entry): ?string
    {
        $entry->loadMissing(['employee', 'run.period']);
        $employee = $entry->employee;
        $period = $entry->run?->period;
        if ($employee === null || $period === null) {
            return null;
        }

        try {
            $prepared = $this->builder->build($employee, $period, $this->settings->getOrCreate());

            return $this->fingerprints->fingerprint($prepared->snapshot);
        } catch (PayrollCalculationException) {
            return null; // inputs no longer produce a valid calculation
        }
    }

    public function isStale(PayrollEntry $entry): bool
    {
        if ($entry->input_fingerprint === null) {
            return true;
        }

        return $this->currentFingerprint($entry) !== $entry->input_fingerprint;
    }
}
