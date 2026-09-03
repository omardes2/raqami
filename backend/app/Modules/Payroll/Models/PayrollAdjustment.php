<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual payroll adjustment keyed by (period, employee) — NOT by run — so it is an
 * authoritative payroll INPUT for the whole period: a replacement run for the same
 * period consumes the exact same adjustment rows (same ids), with no copy. Single
 * money-sign truth: `direction` (earning|deduction) + a strictly positive
 * `amount_minor`. `employee_visible_label` may surface on the generated line;
 * `internal_reason` is management-only and never enters the calculation, snapshot,
 * employee data, audit, or errors. `source_payroll_entry_id` is optional
 * traceability to a prior finalized entry (never an auto retro delta). Immutable
 * once its period is closed (DB trigger). Tenant-owned (RLS).
 */
class PayrollAdjustment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'payroll_period_id', 'employee_id',
        'employee_visible_label', 'direction', 'amount_minor', 'currency',
        'internal_reason', 'source_payroll_entry_id',
        'created_by_user_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'version' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'source_payroll_entry_id');
    }
}
