<?php

namespace App\Modules\Billing\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Manual = 'manual';
}
