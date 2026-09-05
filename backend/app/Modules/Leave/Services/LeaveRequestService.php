<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestKind;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Support\IntervalMath;
use App\Modules\Leave\Support\LeaveComputation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave request lifecycle: authoritative submission (validate → snapshot days →
 * reserve, all in one transaction under the balance lock), non-authoritative
 * preview, and withdrawal. Overlap is coverage-interval aware so two
 * non-overlapping halves may coexist. There is no server draft (D5).
 */
class LeaveRequestService
{
    /** Active statuses that block an overlapping new request. */
    private const ACTIVE_STATUSES = ['pending', 'approved', 'cancellation_pending'];

    public function __construct(
        private readonly LeavePolicyResolver $policyResolver,
        private readonly LeaveEntitlementPeriodService $periods,
        private readonly LeaveRequestCalculator $calculator,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveApprovalRouter $approvals,
        private readonly AuditLogger $audit,
        private readonly LeaveNotifier $notifier,
    ) {}

    /**
     * Non-authoritative preview of a request's effect (never reserves). The final
     * submit recomputes everything inside the transaction — the client is never
     * trusted for coverage/consumption.
     *
     * @param  array<string, mixed>  $input
     * @return array{policy:LeavePolicy, computation:LeaveComputation, available_before:int, available_after:int}
     */
    public function preview(Employee $employee, array $input): array
    {
        $kind = LeaveRequestKind::from($input['request_kind'] ?? 'full_day');
        $start = CarbonImmutable::parse($input['starts_on']);
        $end = CarbonImmutable::parse($input['ends_on']);

        $policy = $this->resolvePolicyOrFail($employee, (string) $input['leave_type_id'], $start);
        $this->validateShape($employee, $policy, $kind, $start, $end);

        $period = $this->periods->resolveOrCreate($employee, (string) $input['leave_type_id'], $policy, $start);
        $computation = $this->calculator->compute($employee, $policy, $kind, $start, $end, $period->starts_on, $period->ends_on);
        $this->assertHalfDayHasSchedule($kind, $computation);

        $balance = $this->balances->rebuildForPeriod($period->fresh());
        $availableBefore = (int) $balance->available_minutes;

        return [
            'policy' => $policy,
            'computation' => $computation,
            'available_before' => $availableBefore,
            'available_after' => $availableBefore - $computation->totalConsumptionMinutes,
        ];
    }

    /**
     * Authoritative submission. Creates a PENDING request, snapshots one row per
     * work_date, reserves the balance (exactly once), then builds the snapshotted
     * approval workflow (auto-approving when the flow is `none`).
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(Employee $employee, array $input, Model $actor, bool $allowNegativeOverride = false): LeaveRequest
    {
        $this->assertEligible($employee);

        $kind = LeaveRequestKind::from($input['request_kind'] ?? 'full_day');
        $start = CarbonImmutable::parse($input['starts_on']);
        $end = CarbonImmutable::parse($input['ends_on']);

        $policy = $this->resolvePolicyOrFail($employee, (string) $input['leave_type_id'], $start);
        $this->validateShape($employee, $policy, $kind, $start, $end);

        return DB::transaction(function () use ($employee, $input, $policy, $kind, $start, $end, $actor, $allowNegativeOverride) {
            $period = $this->periods->resolveOrCreate($employee, (string) $input['leave_type_id'], $policy, $start);
            $computation = $this->calculator->compute($employee, $policy, $kind, $start, $end, $period->starts_on, $period->ends_on);

            if (! $computation->hasEffect()) {
                $this->fail(__('leave.no_coverage'));
            }

            $this->validateAgainstPolicy($policy, $computation);
            $this->assertHalfDayHasSchedule($kind, $computation);
            $this->assertNoOverlap($employee, $computation, null);

            return $this->balances->withLockedBalance($period, function ($balance) use (
                $employee, $input, $policy, $kind, $start, $end, $computation, $period, $actor, $allowNegativeOverride
            ) {
                $consumption = $computation->totalConsumptionMinutes;
                $this->assertSufficient($balance, $policy, $consumption, $allowNegativeOverride);

                $request = LeaveRequest::query()->create([
                    'employee_id' => $employee->getKey(),
                    'leave_type_id' => (string) $input['leave_type_id'],
                    'leave_policy_id' => $policy->getKey(),
                    'entitlement_period_id' => $period->getKey(),
                    'request_kind' => $kind,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                    'requested_consumption_minutes' => $consumption,
                    'requested_coverage_minutes' => $computation->totalCoverageMinutes,
                    'status' => LeaveRequestStatus::Pending,
                    'consumption_basis' => $policy->consumption_basis,
                    'reason' => $input['reason'] ?? null,
                    'submitted_at' => CarbonImmutable::now()->utc(),
                    'version' => 0,
                    'created_by_user_id' => (string) $actor->getKey(),
                ]);

                foreach ($computation->days as $day) {
                    $request->days()->create(array_merge($day->toRow(), [
                        'employee_id' => $employee->getKey(),
                    ]));
                }

                $this->balances->reserve($balance, $consumption, [
                    'leave_request_id' => $request->getKey(),
                    'leave_policy_id' => $policy->getKey(),
                    'reason' => 'leave request reservation',
                    'created_by_user_id' => (string) $actor->getKey(),
                ]);

                $this->audit->log('leave.requested', [
                    'actor' => $actor,
                    'subject' => $request,
                    'metadata' => [
                        'employee_id' => (string) $employee->getKey(),
                        'consumption_minutes' => $consumption,
                        'coverage_minutes' => $computation->totalCoverageMinutes,
                    ],
                ]);

                // Build the snapshotted approval workflow (may auto-finalize).
                $this->approvals->buildForSubmission($request->fresh(), $employee, $policy, $actor);

                // Post-commit: notify named approvers of a request still awaiting
                // them. The `none` flow auto-finalizes (no pending steps), so this
                // no-ops and the "approved" notification fires from finalizeApproval.
                $this->notifier->submitted($request->fresh());

                return $request->fresh();
            });
        });
    }

    /**
     * Withdraw a pending request: release the reservation, cancel outstanding
     * approval steps, mark withdrawn. Idempotent (a re-run on a terminal request
     * is a safe no-op).
     */
    public function withdraw(LeaveRequest $request, Model $actor, ?int $expectedVersion = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $expectedVersion) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequestStatus::Pending) {
                // Idempotent: already withdrawn/terminal.
                if ($request->status === LeaveRequestStatus::Withdrawn) {
                    return $request;
                }
                $this->fail(__('leave.not_withdrawable'));
            }

            $this->assertFresh($request, $expectedVersion);

            $policy = $request->policy;
            if ($policy !== null && ! $policy->allow_withdrawal) {
                $this->fail(__('leave.withdrawal_not_allowed'));
            }

            $period = $request->period;
            $this->balances->withLockedBalance($period, function ($balance) use ($request, $actor) {
                $this->balances->releaseReservation($balance, (int) $request->requested_consumption_minutes, [
                    'leave_request_id' => $request->getKey(),
                    'reason' => 'withdrawal release',
                    'created_by_user_id' => (string) $actor->getKey(),
                ]);
            });

            $this->approvals->cancelOpenSteps($request);

            $request->fill([
                'status' => LeaveRequestStatus::Withdrawn,
                'finalized_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $request->version + 1,
            ])->save();

            $this->audit->log('leave.withdrawn', [
                'actor' => $actor,
                'subject' => $request,
                'metadata' => ['employee_id' => (string) $request->employee_id],
            ]);

            return $request->fresh();
        });
    }

    // --- Validation helpers ---

    private function resolvePolicyOrFail(Employee $employee, string $leaveTypeId, CarbonImmutable $date): LeavePolicy
    {
        $policy = $this->policyResolver->resolve($employee, $leaveTypeId, $date);
        if ($policy === null) {
            $this->fail(__('leave.no_policy'));
        }

        return $policy;
    }

    private function validateShape(Employee $employee, LeavePolicy $policy, LeaveRequestKind $kind, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($end->lessThan($start)) {
            $this->fail(__('leave.invalid_date_range'));
        }

        if ($kind !== LeaveRequestKind::FullDay) {
            $type = LeaveType::query()->find($policy->leave_type_id);
            if (! $policy->allow_half_day || ($type !== null && ! $type->allow_half_day)) {
                $this->fail(__('leave.half_day_not_allowed'));
            }
            if (! $start->isSameDay($end)) {
                $this->fail(__('leave.half_day_single_day'));
            }
        }

        // Notice period + advance-booking window (based on today in server terms).
        $today = CarbonImmutable::now()->startOfDay();
        if ($policy->minimum_notice_days !== null) {
            $earliest = $today->addDays((int) $policy->minimum_notice_days);
            if ($start->lessThan($earliest)) {
                $this->fail(__('leave.notice_too_short'));
            }
        }
        if ($policy->maximum_advance_booking_days !== null) {
            $latest = $today->addDays((int) $policy->maximum_advance_booking_days);
            if ($start->greaterThan($latest)) {
                $this->fail(__('leave.too_far_ahead'));
            }
        }
    }

    private function validateAgainstPolicy(LeavePolicy $policy, LeaveComputation $computation): void
    {
        $minutes = $computation->totalConsumptionMinutes;
        if ($policy->minimum_request_minutes !== null && $minutes < (int) $policy->minimum_request_minutes) {
            $this->fail(__('leave.min_request_minutes'));
        }
        if ($policy->maximum_request_minutes !== null && $minutes > (int) $policy->maximum_request_minutes) {
            $this->fail(__('leave.max_request_minutes'));
        }
    }

    /**
     * Conflict detection on TWO dimensions against active requests sharing a
     * work_date: (1) coverage-interval overlap for scheduled/partial work (so two
     * non-overlapping halves may coexist), and (2) same-date CONSUMPTION conflict
     * for calendar-day-consumed dates that occupy no attendance time (nominal
     * basis) — two active requests may never consume the same logical date,
     * regardless of leave type.
     */
    private function assertNoOverlap(Employee $employee, LeaveComputation $computation, ?string $ignoreRequestId): void
    {
        // The new request's effect per work_date: coverage intervals + does it consume?
        $new = [];
        foreach ($computation->days as $day) {
            if ($day->coverageIntervals === [] && $day->consumptionMinutes <= 0) {
                continue; // excluded day (no coverage, no consumption) → no conflict
            }
            $new[$day->workDate] = [
                'coverage' => $day->coverageIntervals,
                'consumes' => $day->consumptionMinutes > 0,
            ];
        }
        if ($new === []) {
            return;
        }

        $existing = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($ignoreRequestId !== null, fn ($q) => $q->whereKeyNot($ignoreRequestId))
            ->with(['days' => fn ($q) => $q->whereIn('work_date', array_keys($new))])
            ->get();

        foreach ($existing as $request) {
            foreach ($request->days as $day) {
                $date = $day->work_date->toDateString();
                if (! isset($new[$date])) {
                    continue;
                }
                $mine = $new[$date];
                $exCoverage = $day->coverage_intervals ?? [];
                $exConsumes = (int) $day->consumption_minutes > 0;

                // Both occupy attendance time → conflict only if the intervals overlap.
                if ($mine['coverage'] !== [] && $exCoverage !== []) {
                    if (IntervalMath::overlaps($mine['coverage'], $exCoverage)) {
                        $this->fail(__('leave.overlap'));
                    }

                    continue;
                }

                // Otherwise at least one side consumes the WHOLE date (nominal /
                // zero-coverage) — two consuming requests cannot share the date.
                if ($mine['consumes'] && $exConsumes) {
                    $this->fail(__('leave.overlap'));
                }
            }
        }
    }

    /**
     * A half-day request needs actual scheduled work to split — there is no
     * meaningful first/second half of a zero-schedule day (no invented AM/PM).
     */
    private function assertHalfDayHasSchedule(LeaveRequestKind $kind, LeaveComputation $computation): void
    {
        if ($kind === LeaveRequestKind::FullDay) {
            return;
        }
        foreach ($computation->days as $day) {
            if ($day->scheduledMinutes <= 0 && ($day->coverageMinutes > 0 || $day->consumptionMinutes > 0)) {
                $this->fail(__('leave.half_day_requires_schedule'));
            }
        }
    }

    private function assertEligible(Employee $employee): void
    {
        if (! in_array($employee->employment_status, AttendanceEligibility::ELIGIBLE_STATUSES, true)) {
            $this->fail(__('leave.not_eligible'));
        }
    }

    private function assertSufficient($balance, LeavePolicy $policy, int $consumption, bool $allowNegativeOverride): void
    {
        $projected = (int) $balance->available_minutes - $consumption;
        if ($projected >= 0 || $allowNegativeOverride) {
            return;
        }

        if ($policy->allow_negative_balance) {
            $maxNeg = $policy->max_negative_minutes;
            if ($maxNeg === null || $projected >= -((int) $maxNeg)) {
                return;
            }
        }

        $this->fail(__('leave.insufficient_balance'));
    }

    private function assertFresh(LeaveRequest $request, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $request->version !== $expectedVersion) {
            $this->fail(__('leave.stale'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['leave' => [$message]]);
    }
}
