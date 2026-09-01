<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\ScheduleScopeType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assigns a schedule to an organizational scope with explicit effective dates.
 * Resolution precedence, most specific first: employee > team > department >
 * branch > company. Tenant-owned (tenant_id + RLS).
 */
class WorkScheduleAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'work_schedule_id', 'scope_type', 'scope_id',
        'effective_from', 'effective_until', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScheduleScopeType::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
            'priority' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }
}
