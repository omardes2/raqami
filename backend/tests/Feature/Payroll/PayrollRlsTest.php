<?php

namespace Tests\Feature\Payroll;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Proves PostgreSQL RLS — not merely the Eloquent global scope — isolates every
 * Sprint 7 payroll table across tenants using RAW SQL that bypasses Eloquent, and
 * that the platform read-only context can never write a payroll row.
 */
class PayrollRlsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const TABLES = [
        'payroll_settings', 'payroll_components', 'employee_compensations',
        'employee_compensation_components', 'payroll_periods', 'payroll_runs',
        'payroll_entries', 'payroll_entry_lines',
    ];

    private function seedTenant(Tenant $tenant): void
    {
        $this->withinTenant($tenant, function () {
            $owner = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Iso', 'last_name' => 'Pay', 'employment_status' => 'active']);

            // payroll_settings already exists via onboarding; ensure a component,
            // a compensation, a component assignment, a period and a run.
            $component = app(PayrollComponentService::class)->create($owner, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']);
            app(EmployeeCompensationService::class)->create($owner, (string) $employee->getKey(), ['currency' => 'USD', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01']);
            app(EmployeeCompensationComponentService::class)->assign($owner, (string) $employee->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'fixed_amount_minor' => 50000, 'currency' => 'USD', 'effective_from' => '2026-01-01']);
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-01-01']);
            $run = app(PayrollRunService::class)->create($owner, $period);

            // A calculation entry + line (direct rows — the calc job is covered elsewhere).
            $entry = PayrollEntry::query()->create([
                'payroll_run_id' => $run->getKey(), 'employee_id' => $employee->getKey(),
                'currency' => 'USD', 'status' => 'calculated', 'gross_minor' => 400000, 'deduction_minor' => 0, 'net_minor' => 400000,
                'calculation_version' => 'core-v1', 'version' => 1,
            ]);
            PayrollEntryLine::query()->create([
                'payroll_entry_id' => $entry->getKey(), 'line_code' => 'BASE_SALARY', 'line_type' => 'BASE_SALARY',
                'direction' => 'earning', 'source_type' => 'employee_compensation', 'source_id' => null,
                'label_snapshot' => 'Base salary', 'amount_minor' => 400000, 'sort_order' => 0,
            ]);
        });
    }

    public function test_every_payroll_table_is_tenant_isolated_by_rls(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA);

        // Capture tenant A's actual row ids per table (raw SQL, RLS-scoped).
        $idsA = $this->withinTenant($tenantA, function () {
            $ids = [];
            foreach (self::TABLES as $table) {
                $ids[$table] = DB::table($table)->pluck('id')->all();
                $this->assertNotEmpty($ids[$table], "{$table} should have tenant A rows");
            }

            return $ids;
        });

        // Tenant B can see NONE of tenant A's specific rows via raw SQL that
        // bypasses Eloquent scopes. (Both tenants own a payroll_settings row from
        // onboarding, so isolation is proven by id-invisibility, not emptiness.)
        $this->withinTenant($tenantB, function () use ($idsA) {
            foreach (self::TABLES as $table) {
                $visible = DB::table($table)->whereIn('id', $idsA[$table])->count();
                $this->assertSame(0, $visible, "{$table} rows leaked into tenant B");
            }
        });
    }

    public function test_platform_readonly_cannot_write_payroll_tables(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA);

        $affected = app(TenantContext::class)->runAsPlatform(
            fn () => DB::table('employee_compensations')->update(['base_amount_minor' => 1])
        );

        $this->assertSame(0, $affected, 'platform read-only context must not write payroll rows');
    }
}
