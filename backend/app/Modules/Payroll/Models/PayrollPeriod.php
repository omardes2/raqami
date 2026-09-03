<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A full-calendar-month payroll period. Tenant-owned (RLS). */
class PayrollPeriod extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'label', 'period_start', 'period_end', 'timezone',
        'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PayrollPeriodStatus::class,
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }
}
