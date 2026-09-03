<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Employee self-service payslips (Sprint 7.5): READ-ONLY over finalized history.
 * Finalized state is produced by calculating then flipping calculated->finalized and
 * open->closed directly (a transition the immutability triggers permit), so these
 * read tests stay under RefreshDatabase without invoking the top-level finalization
 * service. Covers own-list, detail, privacy, IDOR, cross-tenant, non-finalized, the
 * no-Employee and permission cases, and negative net.
 */
class PayrollPayslipSelfServiceTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner, ?string $linkUserId = null): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $linkUserId) {
            $e = app(EmployeeService::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'JOD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);
            if ($linkUserId !== null) {
                $e->forceFill(['user_id' => $linkUserId])->save();
            }

            return $e->fresh();
        });
    }

    /** Calculate a run for a period; optionally flip it to finalized/closed. */
    private function runFor(Tenant $tenant, $owner, string $start, string $settle = 'finalized'): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner, $start, $settle) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => $start]);
            $run = app(PayrollRunService::class)->create($owner, $period);
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());

            if ($settle === 'calculated') {
                return $run->fresh();
            }
            if ($settle === 'approved') {
                DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'approved', 'approved_at' => now()]);

                return $run->fresh();
            }
            // finalized: flip entries -> run -> period (a transition the triggers allow).
            DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->where('status', 'calculated')
                ->update(['status' => 'finalized', 'finalized_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'finalized', 'finalized_at' => now()]);
            DB::table('payroll_periods')->where('id', $period->getKey())->update(['status' => 'closed']);

            return $run->fresh();
        });
    }

    private function entryId(Tenant $tenant, PayrollRun $run, Employee $emp): string
    {
        return (string) $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->value('id'));
    }

    public function test_own_list_returns_only_finalized_newest_first(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $emp = $this->employee($tenant, $owner, (string) $viewer->getKey());

        $this->runFor($tenant, $owner, '2026-07-01', 'finalized');
        $this->runFor($tenant, $owner, '2026-08-01', 'finalized');
        $this->runFor($tenant, $owner, '2026-09-01', 'calculated');
        $this->runFor($tenant, $owner, '2026-10-01', 'approved');

        $res = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/me/payslips')->assertOk();

        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.period_start', '2026-08-01')
            ->assertJsonPath('data.1.period_start', '2026-07-01')
            ->assertJsonPath('data.0.net_minor', 300000)
            ->assertJsonPath('data.0.currency', 'JOD')
            ->assertJsonPath('data.0.employee_name', 'Jane Doe');
    }

    public function test_detail_returns_safe_grouped_payslip(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $emp = $this->employee($tenant, $owner, (string) $viewer->getKey());
        $run = $this->runFor($tenant, $owner, '2026-09-01', 'finalized');
        $id = $this->entryId($tenant, $run, $emp);

        $res = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/me/payslips/{$id}")->assertOk();

        $res->assertJsonPath('currency', 'JOD')
            ->assertJsonPath('gross_minor', 300000)
            ->assertJsonPath('net_minor', 300000)
            ->assertJsonPath('period.label', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('employee.name', 'Jane Doe')
            ->assertJsonPath('company.name', 'Acme Co')
            ->assertJsonCount(1, 'earnings')
            ->assertJsonPath('earnings.0.line_type', 'BASE_SALARY');

        $raw = $res->json();
        $flat = json_encode($raw);
        foreach (['input_snapshot', 'input_fingerprint', 'error_context', 'negative_net_override', 'calculation_requested_by', 'approved_by', 'finalized_by'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat, "payslip must not expose {$forbidden}");
        }
    }

    public function test_other_employee_payslip_is_404(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $this->employee($tenant, $owner, (string) $viewer->getKey());
        $other = $this->employee($tenant, $owner);
        $run = $this->runFor($tenant, $owner, '2026-09-01', 'finalized');
        $otherId = $this->entryId($tenant, $run, $other);

        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/me/payslips/{$otherId}")->assertStatus(404);
    }

    public function test_cross_tenant_payslip_is_404(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenantA, 'employee');
        $this->employee($tenantA, $ownerA, (string) $viewer->getKey());

        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $empB = $this->employee($tenantB, $ownerB);
        $runB = $this->runFor($tenantB, $ownerB, '2026-09-01', 'finalized');
        $foreignId = $this->entryId($tenantB, $runB, $empB);

        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenantA))
            ->getJson("/api/payroll/me/payslips/{$foreignId}")->assertStatus(404);
    }

    public function test_non_finalized_own_entry_is_404(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $emp = $this->employee($tenant, $owner, (string) $viewer->getKey());
        $calc = $this->runFor($tenant, $owner, '2026-09-01', 'calculated');
        $appr = $this->runFor($tenant, $owner, '2026-10-01', 'approved');

        foreach ([$this->entryId($tenant, $calc, $emp), $this->entryId($tenant, $appr, $emp)] as $id) {
            $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
                ->getJson("/api/payroll/me/payslips/{$id}")->assertStatus(404);
        }
    }

    public function test_user_without_employee_gets_empty_list_and_404_detail(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee'); // has payroll.view_own, no Employee
        $emp = $this->employee($tenant, $owner); // not linked to viewer
        $run = $this->runFor($tenant, $owner, '2026-09-01', 'finalized');
        $id = $this->entryId($tenant, $run, $emp);

        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/me/payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/me/payslips/{$id}")->assertStatus(404);
    }

    public function test_permission_required_even_when_employee_linked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // Department manager has NO payroll.view_own.
        $viewer = $this->memberWithRole($tenant, 'department-manager');
        $this->employee($tenant, $owner, (string) $viewer->getKey());

        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/me/payslips')->assertStatus(403);
    }

    public function test_adjustment_line_privacy(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $emp = $this->employee($tenant, $owner, (string) $viewer->getKey());

        // Calculate, add an adjustment (period-owned), recalc so it folds in, then finalize.
        $period = $this->withinTenant($tenant, fn () => app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']));
        $run = $this->withinTenant($tenant, fn () => app(PayrollRunService::class)->create($owner, $period));
        $calc = function (string $m) use ($tenant, $owner, $run) {
            $this->withinTenant($tenant, function () use ($owner, $run, $m) {
                Queue::fake();
                app(PayrollCalculationService::class)->{$m}($owner, $run->fresh());
                app(PayrollCalculationService::class)->execute((string) $run->getKey());
            });
        };
        $calc('calculate');
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $period->fresh(), (string) $emp->getKey(), [
            'employee_visible_label' => 'Eid Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'JOD',
            'internal_reason' => 'CONFIDENTIAL BOARD DECISION',
        ]));
        $calc('recalculate');
        $this->withinTenant($tenant, function () use ($run, $period) {
            DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->where('status', 'calculated')->update(['status' => 'finalized', 'finalized_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'finalized', 'finalized_at' => now()]);
            DB::table('payroll_periods')->where('id', $period->getKey())->update(['status' => 'closed']);
        });

        $id = $this->entryId($tenant, $run, $emp);
        $res = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/me/payslips/{$id}")->assertOk();

        $flat = json_encode($res->json());
        $this->assertStringContainsString('Eid Bonus', $flat);
        $this->assertStringNotContainsString('CONFIDENTIAL BOARD DECISION', $flat);
        $this->assertStringNotContainsString('internal_reason', $flat);
        $this->assertStringNotContainsString('source_payroll_entry_id', $flat);
        $this->assertStringNotContainsString('created_by', $flat);
    }

    public function test_negative_net_is_shown_without_override_details(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');
        $emp = $this->employee($tenant, $owner, (string) $viewer->getKey());
        $period = $this->withinTenant($tenant, fn () => app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']));
        $run = $this->withinTenant($tenant, fn () => app(PayrollRunService::class)->create($owner, $period));
        $calc = function (string $m) use ($tenant, $owner, $run) {
            $this->withinTenant($tenant, function () use ($owner, $run, $m) {
                Queue::fake();
                app(PayrollCalculationService::class)->{$m}($owner, $run->fresh());
                app(PayrollCalculationService::class)->execute((string) $run->getKey());
            });
        };
        $calc('calculate');
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $period->fresh(), (string) $emp->getKey(), [
            'employee_visible_label' => 'Clawback', 'direction' => 'deduction', 'amount_minor' => 400000, 'currency' => 'JOD',
            'internal_reason' => 'overpayment recovery',
        ]));
        $calc('recalculate');
        $this->withinTenant($tenant, function () use ($run, $period, $viewer) {
            DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->where('status', 'calculated')
                ->update(['status' => 'finalized', 'finalized_at' => now(), 'negative_net_override_by_user_id' => (string) $viewer->getKey(), 'negative_net_override_reason' => 'SECRET OVERRIDE NOTE']);
            DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'finalized', 'finalized_at' => now()]);
            DB::table('payroll_periods')->where('id', $period->getKey())->update(['status' => 'closed']);
        });

        $id = $this->entryId($tenant, $run, $emp);
        $res = $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/me/payslips/{$id}")->assertOk()
            ->assertJsonPath('net_minor', -100000);

        $this->assertStringNotContainsString('SECRET OVERRIDE NOTE', json_encode($res->json()));
    }
}
