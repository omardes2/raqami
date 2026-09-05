<?php

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payroll\Jobs\PayslipNotificationJob;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollFinalizationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CommitsPayrollAtTopLevel;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — payroll finalization enqueues ONE payslip fan-out job post-commit
 * (never per-entry in the request). The job notifies each finalized entry's
 * employee-User that a payslip is available, with NO money in the payload,
 * idempotently (dedupe per entry). Runs at transaction level 0 (real finalize).
 */
class PayrollNotificationTest extends TestCase
{
    use CommitsPayrollAtTopLevel;
    use InteractsWithTenancy;

    private ?string $periodLabel = null;

    private function linkedEmployee(Tenant $tenant, User $owner): array
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $e = app(EmployeeService::class)->create(['first_name' => 'Pay', 'last_name' => 'Slip', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);

            $user = User::factory()->create();
            $e->fill(['user_id' => $user->id])->save();
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);

            return [$e->fresh(), $user];
        });
    }

    private function finalizedRun(Tenant $tenant, User $owner): PayrollRun
    {
        $run = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);
            $this->periodLabel = (string) $period->label;

            return app(PayrollRunService::class)->create($owner, $period);
        });

        $this->withinTenant($tenant, function () use ($owner, $run) {
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });

        $this->withinTenant($tenant, fn () => app(PayrollFinalizationService::class)->finalize($owner, $run->fresh()));

        return $run;
    }

    private function inboxRows(Tenant $tenant, string $userId, string $type): array
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.user_id', ?, false)", [$userId]);
        DB::statement("select set_config('app.platform_readonly', 'off', false)");
        try {
            return DB::table('notifications')->where('type', $type)->get()->all();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_finalization_dispatches_payslip_job_and_job_notifies_without_money(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        [$employee, $user] = $this->linkedEmployee($tenant, $owner);
        $run = $this->finalizedRun($tenant, $owner);

        // A8: exactly one fan-out job dispatched post-commit (queue faked during finalize).
        Queue::assertPushed(PayslipNotificationJob::class, 1);

        // Run the job to deliver (idempotent) and assert delivery + no money.
        $this->withinTenant($tenant, fn () => (new PayslipNotificationJob((string) $run->getKey()))
            ->handle(app(NotificationService::class)));

        $rows = $this->inboxRows($tenant, (string) $user->id, 'payroll.payslip_available');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('payslip_available', $rows[0]->data);
        // No money anywhere in the payload.
        $this->assertStringNotContainsString('300000', $rows[0]->data);
        $this->assertStringNotContainsString('net', $rows[0]->data);
        $this->assertStringNotContainsString('gross', $rows[0]->data);

        // Idempotent: re-running the job (retry / reconcile) creates no duplicate.
        $this->withinTenant($tenant, fn () => (new PayslipNotificationJob((string) $run->getKey()))
            ->handle(app(NotificationService::class)));
        $rows = $this->inboxRows($tenant, (string) $user->id, 'payroll.payslip_available');
        $this->assertCount(1, $rows);
    }

    public function test_job_noops_when_run_not_finalized(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        [$employee, $user] = $this->linkedEmployee($tenant, $owner);

        $run = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-10-01']);

            return app(PayrollRunService::class)->create($owner, $period); // Draft, not finalized
        });

        $this->withinTenant($tenant, fn () => (new PayslipNotificationJob((string) $run->getKey()))
            ->handle(app(NotificationService::class)));

        $this->assertCount(0, $this->inboxRows($tenant, (string) $user->id, 'payroll.payslip_available'));
    }
}
