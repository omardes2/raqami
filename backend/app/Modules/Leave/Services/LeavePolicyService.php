<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\LeavePolicyStatus;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD for leave policies. Enforces the D7 consumption-basis consistency rules
 * (contradiction prevention) so a policy can never "count holidays" while
 * consuming zero scheduled minutes. Every change is audited.
 */
class LeavePolicyService
{
    private const FIELDS = [
        'leave_type_id', 'name', 'effective_from', 'effective_until', 'period_basis',
        'entitlement_method', 'entitlement_minutes',
        'accrual_frequency', 'accrual_minutes', 'proration_enabled',
        'max_balance_minutes', 'allow_negative_balance', 'max_negative_minutes',
        // carry_forward_expiry_days is RESERVED (DB column only) — not writable in
        // Sprint 5 (carried-balance expiry-after-N-days is not implemented).
        'carry_forward_enabled', 'carry_forward_max_minutes',
        'consumption_basis', 'nominal_day_minutes', 'count_holidays', 'count_non_working_days',
        'allow_half_day', 'minimum_request_minutes', 'maximum_request_minutes',
        'minimum_notice_days', 'maximum_advance_booking_days', 'requires_attachment',
        'allow_withdrawal', 'allow_cancellation_request', 'approval_flow',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, mixed $actor = null): LeavePolicy
    {
        $this->assertConsistent($input);

        return DB::transaction(function () use ($input, $actor) {
            $policy = LeavePolicy::query()->create(array_merge(
                array_intersect_key($input, array_flip(self::FIELDS)),
                ['status' => LeavePolicyStatus::Active],
            ));

            $this->audit->log('leave.policy_created', [
                'actor' => $actor,
                'subject' => $policy,
                'metadata' => ['leave_type_id' => (string) $policy->leave_type_id],
            ]);

            return $policy;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(LeavePolicy $policy, array $input, mixed $actor = null): LeavePolicy
    {
        // Validate the merged effective state.
        $this->assertConsistent(array_merge($policy->only([
            'consumption_basis', 'nominal_day_minutes', 'count_holidays', 'count_non_working_days',
        ]), $input));

        return DB::transaction(function () use ($policy, $input, $actor) {
            $policy->fill(array_intersect_key($input, array_flip(self::FIELDS)))->save();

            $this->audit->log('leave.policy_updated', [
                'actor' => $actor,
                'subject' => $policy,
                'metadata' => ['leave_type_id' => (string) $policy->leave_type_id],
            ]);

            return $policy->fresh();
        });
    }

    public function archive(LeavePolicy $policy, mixed $actor = null): LeavePolicy
    {
        return DB::transaction(function () use ($policy, $actor) {
            $policy->fill(['status' => LeavePolicyStatus::Archived])->save();

            $this->audit->log('leave.policy_archived', [
                'actor' => $actor,
                'subject' => $policy,
            ]);

            return $policy->fresh();
        });
    }

    /**
     * D7 contradiction-prevention:
     *  1. nominal_calendar_day basis requires nominal_day_minutes > 0.
     *  2. count_holidays / count_non_working_days require the nominal basis
     *     (scheduled-minutes basis consumes zero on those days, so "counting"
     *     them would be a self-contradiction).
     *
     * @param  array<string, mixed>  $input
     */
    private function assertConsistent(array $input): void
    {
        $basis = $input['consumption_basis'] ?? ConsumptionBasis::ScheduledMinutes->value;
        $basis = $basis instanceof ConsumptionBasis ? $basis->value : $basis;
        $nominal = $input['nominal_day_minutes'] ?? null;
        $countHolidays = (bool) ($input['count_holidays'] ?? false);
        $countNonWorking = (bool) ($input['count_non_working_days'] ?? false);

        if ($basis === ConsumptionBasis::NominalCalendarDay->value && (int) $nominal <= 0) {
            $this->fail(__('leave.nominal_minutes_required'));
        }

        if (($countHolidays || $countNonWorking) && $basis !== ConsumptionBasis::NominalCalendarDay->value) {
            $this->fail(__('leave.count_days_requires_nominal'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['leave' => [$message]]);
    }

    public function typeName(string $leaveTypeId): ?string
    {
        return LeaveType::query()->find($leaveTypeId)?->name;
    }
}
