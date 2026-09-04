<?php

namespace Tests\Feature\Payroll;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollReportService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8A Phase 2 payroll reports: finalized-only financial source, currency
 * grouping (never combined), company-wide permission, tenant isolation, privacy,
 * negative net, and finalized line totals. Finalized state is produced by inserting
 * calculated rows into a draft run then flipping calculated->finalized / draft->
 * finalized / open->closed (the transition the immutability triggers permit).
 */
class PayrollReportTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function emp(Tenant $tenant, string $number): Employee
    {
        return $this->withinTenant($tenant, fn () => Employee::factory()->create([
            'employee_number' => $number, 'first_name' => 'E', 'last_name' => $number, 'employment_status' => 'active',
        ]));
    }

    /**
     * Seed one period+run and its entries at the given settle level.
     *
     * @param  array<int, array{emp:Employee, currency:string, gross:int, ded:int, net:int, lines?:array<int,array{type:string,dir:string,label:string,amount:int}>}>  $specs
     * @param  'finalized'|'entries_only'|'period_open'|'calculated'  $settle
     */
    private function seedRun(Tenant $tenant, string $start, array $specs, string $settle = 'finalized'): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($start, $specs, $settle) {
            // payroll_periods_full_month_chk requires a full calendar month.
            $monthStart = CarbonImmutable::parse($start)->startOfMonth();
            $period = PayrollPeriod::create([
                'label' => $monthStart->format('Y-m'),
                'period_start' => $monthStart->toDateString(),
                'period_end' => $monthStart->endOfMonth()->toDateString(),
                'timezone' => 'UTC',
            ]);
            $run = PayrollRun::create(['payroll_period_id' => $period->getKey()]);

            foreach ($specs as $s) {
                $entry = PayrollEntry::create([
                    'payroll_run_id' => $run->getKey(), 'employee_id' => $s['emp']->getKey(),
                    'currency' => $s['currency'], 'status' => 'calculated',
                    'gross_minor' => $s['gross'], 'deduction_minor' => $s['ded'], 'net_minor' => $s['net'],
                    'employee_snapshot' => ['employee_number' => $s['emp']->employee_number, 'name' => 'E '.$s['emp']->employee_number, 'job_title' => null],
                ]);
                foreach ($s['lines'] ?? [] as $i => $l) {
                    PayrollEntryLine::create([
                        'payroll_entry_id' => $entry->getKey(), 'line_code' => 'L'.$i,
                        'line_type' => $l['type'], 'direction' => $l['dir'], 'source_type' => 'component',
                        'label_snapshot' => $l['label'], 'amount_minor' => $l['amount'], 'sort_order' => $i,
                    ]);
                }
                // Any private override columns are written while the entry is still
                // 'calculated' — after finalization the immutability trigger freezes it,
                // exactly as production does (the override is decided pre-finalize).
                if (! empty($s['override'])) {
                    DB::table('payroll_entries')->where('id', $entry->getKey())->update($s['override']);
                }
            }

            if ($settle === 'calculated') {
                return $run->fresh();
            }
            // entries -> finalized always for the non-calculated settle levels.
            DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->where('status', 'calculated')
                ->update(['status' => 'finalized', 'finalized_at' => now()]);
            if ($settle === 'entries_only') {
                return $run->fresh(); // run stays draft
            }
            DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'finalized', 'finalized_at' => now()]);
            if ($settle === 'period_open') {
                return $run->fresh(); // period stays open
            }
            DB::table('payroll_periods')->where('id', $period->getKey())->update(['status' => 'closed']);

            return $run->fresh();
        });
    }

    public function test_currency_golden_grouped_never_combined(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $e1 = $this->emp($tenant, 'A1');
        $e2 = $this->emp($tenant, 'A2');
        $e3 = $this->emp($tenant, 'A3');
        $this->seedRun($tenant, '2026-07-01', [
            ['emp' => $e1, 'currency' => 'JOD', 'gross' => 1000000, 'ded' => 100000, 'net' => 900000],
            ['emp' => $e2, 'currency' => 'JOD', 'gross' => 500000, 'ded' => 50000, 'net' => 450000],
            ['emp' => $e3, 'currency' => 'USD', 'gross' => 20000, 'ded' => 1000, 'net' => 19000],
        ]);

        $out = $this->withinTenant($tenant, fn () => app(PayrollReportService::class)->summary([]));
        $byC = collect($out['by_currency'])->keyBy('currency');

        $this->assertSame(1500000, $byC['JOD']['gross_minor']);
        $this->assertSame(150000, $byC['JOD']['deduction_minor']);
        $this->assertSame(1350000, $byC['JOD']['net_minor']);
        $this->assertSame(20000, $byC['USD']['gross_minor']);
        $this->assertSame(1000, $byC['USD']['deduction_minor']);
        $this->assertSame(19000, $byC['USD']['net_minor']);
        // No cross-currency combined total anywhere.
        $flat = json_encode($out);
        foreach (['grand_total', 'combined', 'total_minor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }
        $this->assertArrayNotHasKey('gross_minor', $out); // only per-currency carries money
    }

    #[DataProvider('nonFinalizedSettles')]
    public function test_non_finalized_excluded_from_financials(string $settle): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $e = $this->emp($tenant, 'N1');
        $this->seedRun($tenant, '2026-08-01', [
            ['emp' => $e, 'currency' => 'JOD', 'gross' => 900000, 'ded' => 0, 'net' => 900000],
        ], $settle);

        $out = $this->withinTenant($tenant, fn () => app(PayrollReportService::class)->summary([]));
        $this->assertSame(0, $out['entry_count']);
        $this->assertSame([], $out['by_currency']);
    }

    /** @return array<int, array{0:string}> */
    public static function nonFinalizedSettles(): array
    {
        return [['calculated'], ['entries_only'], ['period_open']];
    }

    public function test_negative_net_included_without_override_details(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $e = $this->emp($tenant, 'Z1');
        // A finalized entry with a genuinely negative net AND a stored private
        // override reason/actor (set pre-finalize, then frozen by the immutability
        // trigger — exactly the production shape).
        $this->seedRun($tenant, '2026-09-01', [
            ['emp' => $e, 'currency' => 'JOD', 'gross' => 100000, 'ded' => 250000, 'net' => -150000, 'override' => [
                'negative_net_override_reason' => 'SECRET OVERRIDE', 'negative_net_override_by_user_id' => $owner->getKey(),
            ]],
        ]);

        $out = $this->withinTenant($tenant, fn () => app(PayrollReportService::class)->summary([]));
        $this->assertSame(-150000, collect($out['by_currency'])->firstWhere('currency', 'JOD')['net_minor']);
        $this->assertStringNotContainsString('SECRET OVERRIDE', json_encode($out));
    }

    public function test_components_report_line_totals_are_safe(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $e = $this->emp($tenant, 'C1');
        $this->seedRun($tenant, '2026-09-01', [
            ['emp' => $e, 'currency' => 'JOD', 'gross' => 300000, 'ded' => 50000, 'net' => 250000, 'lines' => [
                ['type' => 'BASE_SALARY', 'dir' => 'earning', 'label' => 'Base Salary', 'amount' => 300000],
                ['type' => 'ADJUSTMENT_DEDUCTION', 'dir' => 'deduction', 'label' => 'Loan Repayment', 'amount' => 50000],
            ]],
        ]);

        $out = $this->withinTenant($tenant, fn () => app(PayrollReportService::class)->components([]));
        $labels = collect($out)->pluck('label');
        $this->assertTrue($labels->contains('Base Salary'));
        $this->assertTrue($labels->contains('Loan Repayment'));
        $flat = json_encode($out);
        foreach (['source_id', 'source_type', 'metadata', 'internal_reason', 'source_payroll_entry_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }
    }

    public function test_cross_tenant_isolation(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $this->seedRun($tenantA, '2026-09-01', [['emp' => $this->emp($tenantA, 'A'), 'currency' => 'JOD', 'gross' => 100000, 'ded' => 0, 'net' => 100000]]);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $this->seedRun($tenantB, '2026-09-01', [['emp' => $this->emp($tenantB, 'B'), 'currency' => 'USD', 'gross' => 55555, 'ded' => 0, 'net' => 55555]]);

        $out = $this->withinTenant($tenantA, fn () => app(PayrollReportService::class)->summary([]));
        $this->assertSame(['JOD'], $out['currencies']); // tenant B's USD absent
    }

    public function test_summary_privacy_no_internal_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedRun($tenant, '2026-09-01', [['emp' => $this->emp($tenant, 'P1'), 'currency' => 'JOD', 'gross' => 100000, 'ded' => 0, 'net' => 100000]]);

        $flat = json_encode($this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/reports/summary')->assertOk()->json());
        foreach (['input_snapshot', 'input_fingerprint', 'error_context', 'internal_reason', 'source_payroll_entry_id', 'negative_net_override', 'calculation_version', 'created_by'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat, "must not expose {$forbidden}");
        }
    }

    #[DataProvider('allowedRoles')]
    public function test_authorized_roles_may_view(string $role): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $user = $role === 'owner' ? $owner : $this->memberWithRole($tenant, $role);
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/reports/summary')->assertOk();
    }

    /** @return array<int, array{0:string}> */
    public static function allowedRoles(): array
    {
        return [['owner'], ['admin'], ['accountant']];
    }

    #[DataProvider('deniedRoles')]
    public function test_unauthorized_roles_denied(string $role): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, $role);
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/payroll/reports/summary')->assertStatus(403);
    }

    /** @return array<int, array{0:string}> */
    public static function deniedRoles(): array
    {
        return [['hr-manager'], ['department-manager'], ['team-leader'], ['employee']];
    }
}
