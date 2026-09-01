<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\HolidayType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A holiday entry (single- or multi-day). Tenant-owned (tenant_id + RLS). */
class Holiday extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'holiday_calendar_id', 'name', 'date', 'end_date',
        'type', 'is_paid', 'description',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'end_date' => 'date',
            'type' => HolidayType::class,
            'is_paid' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }
}
