<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's payroll result within a run. Totals are server-derived only. */
class PayrollEntry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'employee_id', 'currency', 'status',
        'employee_snapshot', 'input_snapshot', 'input_fingerprint',
        'gross_minor', 'deduction_minor', 'net_minor',
        'calculation_version', 'calculated_at', 'finalized_at',
        'error_code', 'error_context',
        'negative_net_override_by_user_id', 'negative_net_override_reason',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayrollEntryStatus::class,
            'employee_snapshot' => 'array',
            'input_snapshot' => 'array',
            'error_context' => 'array',
            'gross_minor' => 'integer',
            'deduction_minor' => 'integer',
            'net_minor' => 'integer',
            'calculated_at' => 'datetime',
            'finalized_at' => 'datetime',
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

    /** @return HasMany<PayrollEntryLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'payroll_entry_id');
    }
}
