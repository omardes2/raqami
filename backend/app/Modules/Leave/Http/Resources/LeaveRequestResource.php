<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRequest */
class LeaveRequestResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'leave_policy_id' => $this->leave_policy_id,
            'entitlement_period_id' => $this->entitlement_period_id,
            'request_kind' => $this->request_kind?->value,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'requested_consumption_minutes' => (int) $this->requested_consumption_minutes,
            'requested_coverage_minutes' => (int) $this->requested_coverage_minutes,
            'status' => $this->status?->value,
            'reason' => $this->reason,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'finalized_at' => $this->finalized_at?->toISOString(),
            'cancellation_requested_at' => $this->cancellation_requested_at?->toISOString(),
            'decision_note' => $this->decision_note,
            'version' => (int) $this->version,
            'missing_required_attachment' => $this->missingRequiredAttachment(),
            'days' => $this->whenLoaded('days', fn () => $this->days->map(fn ($d) => [
                'work_date' => $d->work_date?->toDateString(),
                'scheduled_minutes' => (int) $d->scheduled_minutes,
                'coverage_minutes' => (int) $d->coverage_minutes,
                'consumption_minutes' => (int) $d->consumption_minutes,
                'portion' => $d->portion?->value,
                'excluded_reason' => $d->excluded_reason,
            ])),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($a) => [
                'step_order' => (int) $a->step_order,
                'purpose' => $a->purpose?->value,
                'approver_type' => $a->approver_type?->value,
                'status' => $a->status?->value,
                'reviewed_at' => $a->reviewed_at?->toISOString(),
                'note' => $a->note,
            ])),
        ];
    }
}
