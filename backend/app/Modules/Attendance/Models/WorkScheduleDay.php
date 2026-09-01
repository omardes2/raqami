<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-schedule-day configuration. For WEEKLY schedules `weekday` is 0=Sun..6=Sat;
 * for CYCLIC schedules (work_schedules.cycle_length_days set) it is the day-index
 * 0..(cycle_length-1). Expected hours live in work_schedule_segments (split
 * shifts); the day's start_time/end_time are compatibility fields for the
 * backfilled default segment. end_time <= start_time denotes overnight.
 * Tenant-owned.
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

    public function segments(): HasMany
    {
        return $this->hasMany(WorkScheduleSegment::class)->orderBy('sequence');
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
