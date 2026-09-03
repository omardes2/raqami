<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated recurring component assigned to an employee. Tenant-owned (RLS). */
class EmployeeCompensationComponent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'payroll_component_id', 'fixed_amount_minor',
        'rate_bps', 'currency', 'effective_from', 'effective_to',
        'created_by_user_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount_minor' => 'integer',
            'rate_bps' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
