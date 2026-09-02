<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\ApprovalPurpose;
use App\Modules\Leave\Enums\ApprovalStatus;
use App\Modules\Leave\Enums\ApprovalStepType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshotted approval step. hr_pool steps carry no approver_user_id — any
 * holder of required_permission within the snapshotted scope may act.
 * Tenant-owned (tenant_id + RLS).
 */
class LeaveRequestApproval extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'leave_request_id', 'step_order', 'purpose',
        'approver_type', 'approver_user_id', 'required_permission',
        'scope_type', 'scope_id', 'status', 'reviewed_by_user_id', 'reviewed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'purpose' => ApprovalPurpose::class,
            'approver_type' => ApprovalStepType::class,
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
