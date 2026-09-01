<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\WorkScheduleStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable work schedule header. Per-weekday hours live in work_schedule_days.
 * Tenant-owned (tenant_id + RLS).
 */
class WorkSchedule extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'timezone', 'status', 'description',
        'grace_minutes', 'break_minutes', 'overtime_after_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkScheduleStatus::class,
            'grace_minutes' => 'integer',
            'break_minutes' => 'integer',
            'overtime_after_minutes' => 'integer',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkScheduleAssignment::class);
    }
}
