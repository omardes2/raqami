<?php

namespace App\Modules\Employees\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR/business timeline (distinct from the security Audit Log). */
class EmployeeHistoryEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    const UPDATED_AT = null; // append-oriented

    protected $fillable = [
        'tenant_id', 'employee_id', 'event_type', 'effective_date',
        'actor_user_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
