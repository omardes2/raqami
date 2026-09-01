<?php

namespace App\Modules\Billing\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /** Advance a period start by one interval. */
    public function advance(\DateTimeInterface $from): CarbonInterface
    {
        $date = Carbon::parse($from);

        return $this === self::Annual ? $date->addYear() : $date->addMonth();
    }
}
