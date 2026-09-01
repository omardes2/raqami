<?php

namespace App\Modules\Billing\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
