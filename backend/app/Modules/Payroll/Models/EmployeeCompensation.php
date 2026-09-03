<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated monthly base salary. Integer minor units. Tenant-owned (RLS). */
class EmployeeCompensation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    // "compensation" is uncountable, so the inflector would resolve the table to
    // the singular form; pin it to the plural table the migration creates.
    protected $table = 'employee_compensations';

    protected $fillable = [
        'tenant_id', 'employee_id', 'currency', 'base_amount_minor',
        'overtime_rate_minor_per_hour', 'effective_from', 'effective_to',
        'created_by_user_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'base_amount_minor' => 'integer',
            'overtime_rate_minor_per_hour' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
