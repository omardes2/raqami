<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-weekday configuration for a schedule (weekday 0=Sunday .. 6=Saturday).
 * end_time <= start_time denotes an OVERNIGHT window. Tenant-owned.
 */
class WorkScheduleDay extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'work_schedule_id', 'weekday', 'is_working_day',
        'start_time', 'end_time', 'break_minutes', 'grace_minutes',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_working_day' => 'boolean',
            'break_minutes' => 'integer',
            'grace_minutes' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    /** True when end_time is at/before start_time, i.e. the shift crosses midnight. */
    public function isOvernight(): bool
    {
        return $this->is_working_day
            && $this->start_time !== null
            && $this->end_time !== null
            && $this->end_time <= $this->start_time;
    }
}
