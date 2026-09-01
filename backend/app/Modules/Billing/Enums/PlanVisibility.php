<?php

namespace App\Modules\Billing\Enums;

enum PlanVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case EnterpriseOnly = 'enterprise_only';
}
