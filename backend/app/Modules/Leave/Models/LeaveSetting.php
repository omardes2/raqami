<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\ApprovalFlow;
use App\Modules\Leave\Enums\PeriodType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant leave defaults (one row per tenant). display_day_minutes is
 * display-only; canonical accounting stays integer minutes. Tenant-owned + RLS.
 */
class LeaveSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'default_period_basis', 'leave_year_start_month', 'leave_year_start_day',
        'default_approval_flow', 'allow_withdrawal', 'allow_cancellation_request', 'display_day_minutes',
    ];

    protected function casts(): array
    {
        return [
            'default_period_basis' => PeriodType::class,
            'leave_year_start_month' => 'integer',
            'leave_year_start_day' => 'integer',
            'default_approval_flow' => ApprovalFlow::class,
            'allow_withdrawal' => 'boolean',
            'allow_cancellation_request' => 'boolean',
            'display_day_minutes' => 'integer',
        ];
    }
}
