<?php

namespace App\Modules\Billing\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Refunded = 'refunded';
}
