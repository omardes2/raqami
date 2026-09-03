<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payroll run lifecycle envelope (Phase-1 skeleton). Currency-neutral: no
 * currency and no scalar totals live here. Tenant-owned (RLS).
 */
class PayrollRun extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'payroll_period_id', 'status', 'calculation_version',
        'calculation_requested_by_user_id', 'calculated_at',
        'approved_by_user_id', 'approved_at',
        'finalized_by_user_id', 'finalized_at',
        'cancelled_by_user_id', 'cancelled_at',
        'version', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}
