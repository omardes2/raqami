<?php

namespace App\Modules\Billing\Enums;

enum BankTransferStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
