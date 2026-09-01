<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reusable tenant holiday calendar. Tenant-owned (tenant_id + RLS). */
class HolidayCalendar extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'name', 'code', 'description', 'timezone', 'status'];

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HolidayCalendarAssignment::class);
    }
}
