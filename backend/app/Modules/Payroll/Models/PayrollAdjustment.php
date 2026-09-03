<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual payroll adjustment keyed by (run, employee) — NOT by entry — so it
 * survives recalculation (entries are regenerated; the adjustment is re-read as an
 * authoritative input each calculation). Single money-sign truth: `direction`
 * (earning|deduction) + a NON-NEGATIVE `amount_minor`. `reason` is mandatory.
 * Immutable once its period is closed (DB trigger). Tenant-owned (RLS).
 */
class PayrollAdjustment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'employee_id',
        'label', 'direction', 'amount_minor', 'currency', 'reason',
        'created_by_user_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
