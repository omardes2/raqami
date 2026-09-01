<?php

namespace App\Modules\Billing\Enums;

enum CouponType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
}
