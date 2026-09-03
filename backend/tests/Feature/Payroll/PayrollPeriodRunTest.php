<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Monthly payroll periods (full calendar months only) and the Phase-1 run
 * skeleton (create/cancel with exactly one active run per period). Calculation
 * and finalization are out of Phase-1 scope.
 */
class PayrollPeriodRunTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_full_calendar_month_is_accepted_and_period_end_is_derived(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-02-01']);

            $this->assertSame('2026-02-01', $period->period_start->toDateString());
            // February 2026 has 28 days — the end is derived, not trusted from input.
            $this->assertSame('2026-02-28', $period->period_end->toDateString());
            $this->assertSame(PayrollPeriodStatus::Open, $period->status);
            $this->assertSame('2026-02', $period->label);
        });
    }

    public function test_non_month_start_and_partial_month_are_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(PayrollPeriodService::class);

            // Does not start on the first of a month.
            try {
                $svc->create($owner, ['period_start' => '2026-02-15']);
                $this->fail('a mid-month start should be rejected');
            } catch (ValidationException) {
            }

            // Starts on the first but a wrong (partial) end is supplied.
            try {
                $svc->create($owner, ['period_start' => '2026-02-01', 'period_end' => '2026-02-20']);
                $this->fail('a partial month end should be rejected');
            } catch (ValidationException) {
            }

            // Neither rejected attempt created a period.
            $this->assertSame(0, PayrollPeriod::query()->count());
        });
    }

    public function test_duplicate_month_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(PayrollPeriodService::class);
            $svc->create($owner, ['period_start' => '2026-03-01']);

            $this->expectException(ValidationException::class);
            $svc->create($owner, ['period_start' => '2026-03-01']);
        });
    }

    public function test_period_snapshots_the_tenant_payroll_timezone(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            app(PayrollSettingsService::class)->update($owner, ['payroll_timezone' => 'Asia/Hebron']);
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-04-01']);
            $this->assertSame('Asia/Hebron', $period->timezone);

            // Changing settings later never rewrites the historical snapshot.
            app(PayrollSettingsService::class)->update($owner, ['payroll_timezone' => 'UTC']);
            $this->assertSame('Asia/Hebron', $period->fresh()->timezone);
        });
    }

    public function test_exactly_one_active_run_per_period_and_cancel_allows_replacement(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-05-01']);
            $runs = app(PayrollRunService::class);

            $first = $runs->create($owner, $period);
            $this->assertSame(PayrollRunStatus::Draft, $first->status);

            // A second active run for the same period is rejected.
            try {
                $runs->create($owner, $period);
                $this->fail('a second active run should be rejected');
            } catch (ValidationException) {
            }

            // Cancelling the first frees the period for a replacement run.
            $cancelled = $runs->cancel($owner, $first);
            $this->assertSame(PayrollRunStatus::Cancelled, $cancelled->status);
            $this->assertNotNull($cancelled->cancelled_at);

            $replacement = $runs->create($owner, $period);
            $this->assertSame(PayrollRunStatus::Draft, $replacement->status);

            // One cancelled + one active draft.
            $this->assertSame(2, PayrollRun::query()->where('payroll_period_id', $period->getKey())->count());
            $this->assertSame(1, PayrollRun::query()->where('payroll_period_id', $period->getKey())
                ->where('status', '!=', PayrollRunStatus::Cancelled->value)->count());
        });
    }

    public function test_cancelled_run_cannot_be_cancelled_again(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-06-01']);
            $runs = app(PayrollRunService::class);
            $run = $runs->create($owner, $period);
            $runs->cancel($owner, $run);

            // A terminal (cancelled) run is not cancellable — the state machine refuses.
            $this->expectException(ValidationException::class);
            $runs->cancel($owner, $run->fresh());
        });
    }

    public function test_run_status_state_machine_transitions(): void
    {
        // Pure enum contract — the legal transitions the later phases will rely on.
        $this->assertTrue(PayrollRunStatus::Draft->canTransitionTo(PayrollRunStatus::Calculating));
        $this->assertTrue(PayrollRunStatus::Draft->canTransitionTo(PayrollRunStatus::Cancelled));
        $this->assertFalse(PayrollRunStatus::Draft->canTransitionTo(PayrollRunStatus::Finalized));
        $this->assertTrue(PayrollRunStatus::Calculated->canTransitionTo(PayrollRunStatus::Approved));
        $this->assertTrue(PayrollRunStatus::Approved->canTransitionTo(PayrollRunStatus::Finalized));
        $this->assertTrue(PayrollRunStatus::Finalized->isTerminal());
        $this->assertTrue(PayrollRunStatus::Cancelled->isTerminal());
        $this->assertFalse(PayrollRunStatus::Finalized->canTransitionTo(PayrollRunStatus::Cancelled));
    }

    public function test_run_cannot_be_created_for_a_closed_period(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-07-01']);
            // Force the period closed (period closing is a later phase; we assert the guard).
            $period->forceFill(['status' => PayrollPeriodStatus::Closed])->save();

            $this->expectException(ValidationException::class);
            app(PayrollRunService::class)->create($owner, $period->fresh());
        });
    }
}
