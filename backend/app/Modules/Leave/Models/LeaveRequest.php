<?php

namespace App\Modules\Leave\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\LeaveRequestKind;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The leave request aggregate. No server draft (D5). consumption drives balance,
 * coverage drives attendance. Tenant-owned (tenant_id + RLS).
 */
class LeaveRequest extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'entitlement_period_id',
        'request_kind', 'starts_on', 'ends_on',
        'requested_consumption_minutes', 'requested_coverage_minutes',
        'status', 'consumption_basis', 'reason',
        'submitted_at', 'finalized_at', 'cancellation_requested_at', 'decision_note',
        'version', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_kind' => LeaveRequestKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'requested_consumption_minutes' => 'integer',
            'requested_coverage_minutes' => 'integer',
            'status' => LeaveRequestStatus::class,
            'consumption_basis' => ConsumptionBasis::class,
            'submitted_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlementPeriod::class, 'entitlement_period_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveRequestApproval::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveRequestAttachment::class);
    }

    /**
     * Whether a required supporting document is still missing. Approval is blocked
     * until this is false (see LeaveApprovalService). Surfaced to the UI so a
     * pending request clearly shows "document required before approval".
     */
    public function missingRequiredAttachment(): bool
    {
        $this->loadMissing(['policy', 'leaveType', 'attachments']);

        $requires = ($this->policy?->requires_attachment ?? false)
            || ($this->leaveType?->requires_attachment ?? false);
        if (! $requires) {
            return false;
        }

        $threshold = $this->leaveType?->attachment_required_after_minutes;
        if ($threshold !== null && (int) $this->requested_consumption_minutes < (int) $threshold) {
            return false;
        }

        return $this->attachments->count() === 0;
    }
}
