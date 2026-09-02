<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\LeaveTypeCategory;
use App\Modules\Leave\Enums\LeaveTypeStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant catalog of leave kinds. Category is generic (no legal rules); entitlement
 * lives entirely in policies. Tenant-owned (tenant_id + RLS).
 */
class LeaveType extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'category', 'status',
        'paid_classification', 'requires_attachment', 'attachment_required_after_minutes',
        'allow_half_day', 'allow_hourly', 'color',
    ];

    protected function casts(): array
    {
        return [
            'category' => LeaveTypeCategory::class,
            'status' => LeaveTypeStatus::class,
            'requires_attachment' => 'boolean',
            'attachment_required_after_minutes' => 'integer',
            'allow_half_day' => 'boolean',
            'allow_hourly' => 'boolean',
        ];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
