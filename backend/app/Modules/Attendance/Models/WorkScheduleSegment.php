<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expected work segment within a schedule day (split-shift). Tenant-owned. */
class WorkScheduleSegment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'work_schedule_day_id', 'sequence',
        'start_time', 'end_time', 'break_minutes', 'grace_minutes', 'overtime_after_minutes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'break_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'overtime_after_minutes' => 'integer',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleDay::class, 'work_schedule_day_id');
    }

    /** True when end_time is at/before start_time (crosses midnight). */
    public function isOvernight(): bool
    {
        return $this->start_time !== null && $this->end_time !== null
            && $this->end_time <= $this->start_time;
    }
}
