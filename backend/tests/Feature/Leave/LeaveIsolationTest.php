<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveSettingsService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Proves PostgreSQL RLS — not just the app scope — isolates EVERY Sprint 5 leave
 * table across tenants using RAW SQL that bypasses Eloquent, that the platform
 * read-only context can never write them, and that the ledger is append-only.
 */
class LeaveIsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const TABLES = [
        'leave_types', 'leave_policies', 'leave_policy_assignments',
        'leave_entitlement_periods', 'leave_balances', 'leave_balance_transactions',
        'leave_requests', 'leave_request_days', 'leave_request_approvals',
        'leave_request_attachments', 'leave_settings',
    ];

    private function seedTenant(Tenant $tenant): void
    {
        $this->withinTenant($tenant, function () {
            app(LeaveSettingsService::class)->current();

            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Iso', 'last_name' => 'Leave', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'manager',
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));

            $request = app(LeaveRequestService::class)->submit($employee->fresh(), [
                'leave_type_id' => $type->getKey(), 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            LeaveRequestAttachment::query()->create([
                'leave_request_id' => $request->getKey(),
                'storage_key' => 'tenants/x/leave/y/z.pdf',
                'original_filename' => 'z.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
            ]);
        });
    }

    public function test_every_leave_table_is_tenant_isolated_by_rls(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA);

        // Every table has rows for tenant A.
        $this->withinTenant($tenantA, function () {
            foreach (self::TABLES as $table) {
                $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} should have tenant A rows");
            }
        });

        // Tenant B (raw SQL) sees NONE of them.
        $this->withinTenant($tenantB, function () {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} leaked into tenant B");
            }
        });
    }

    public function test_platform_readonly_cannot_write_leave_tables(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA);

        $affected = app(TenantContext::class)->runAsPlatform(
            fn () => DB::table('leave_requests')->update(['reason' => 'tampered'])
        );

        $this->assertSame(0, $affected, 'platform read-only context must not write leave rows');
    }

    public function test_leave_ledger_is_append_only(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA);

        $this->withinTenant($tenantA, function () {
            $id = DB::table('leave_balance_transactions')->value('id');
            $this->assertNotNull($id);

            // No UPDATE policy exists on the ledger, so RLS exposes zero rows to an
            // UPDATE (0 affected) — the value is immutable for the app role. The
            // mutation-reject trigger is the additional backstop for a superuser.
            $affected = DB::table('leave_balance_transactions')->where('id', $id)->update(['minutes' => 999999]);
            $this->assertSame(0, $affected);
        });
    }
}
