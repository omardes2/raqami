<?php

namespace App\Modules\Billing\Support;

use App\Support\Money\CurrencyMetadata as SharedCurrencyMetadata;

/**
 * Backwards-compatible Billing facade over the shared currency metadata helper
 * (App\Support\Money\CurrencyMetadata). The neutral logic + exponent map were
 * extracted to App\Support so other modules (Payroll) can reuse them without
 * depending on Billing. Existing `App\Modules\Billing\Support\CurrencyMetadata`
 * imports and behavior are unchanged — every method is inherited verbatim.
 */
class CurrencyMetadata extends SharedCurrencyMetadata {}
