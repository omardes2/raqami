<?php

namespace Tests\Feature\Payroll;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The full-calendar-month invariant for payroll_periods is enforced at the DATABASE
 * layer (CHECK constraint), not only in PayrollPeriodService — so a direct SQL
 * writer that bypasses the service still cannot create a half-month or cross-month
 * period. period_start must be the first of a month and period_end its last day.
 */
class PayrollPeriodDbConstraintTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Insert a period row via raw SQL (bypassing the service). Returns the caught SQLSTATE, or null. */
    private function rawInsert(Tenant $tenant, string $start, string $end): ?string
    {
        return $this->withinTenant($tenant, function () use ($tenant, $start, $end) {
            try {
                // Savepoint so a CHECK violation does not wedge the outer test transaction.
                DB::transaction(function () use ($tenant, $start, $end) {
                    DB::table('payroll_periods')->insert([
                        'id' => (string) Str::ulid(),
                        'tenant_id' => $tenant->getKey(),
                        'label' => 'raw',
                        'period_start' => $start,
                        'period_end' => $end,
                        'timezone' => 'UTC',
                        'status' => 'open',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

                return null;
            } catch (QueryException $e) {
                return $e->getCode();
            }
        });
    }

    public function test_valid_full_month_is_accepted_by_direct_sql(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->assertNull($this->rawInsert($tenant, '2026-02-01', '2026-02-28'), 'a valid full month must be accepted');

        $this->withinTenant($tenant, function () {
            $this->assertSame(1, DB::table('payroll_periods')->where('label', 'raw')->count());
        });
    }

    public function test_mid_month_start_is_rejected_by_direct_sql(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        // 23514 = check_violation
        $this->assertSame('23514', $this->rawInsert($tenant, '2026-02-15', '2026-02-28'));
    }

    public function test_wrong_end_date_is_rejected_by_direct_sql(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->assertSame('23514', $this->rawInsert($tenant, '2026-02-01', '2026-02-20'));
    }

    public function test_cross_month_range_is_rejected_by_direct_sql(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->assertSame('23514', $this->rawInsert($tenant, '2026-02-01', '2026-03-31'));
    }

    public function test_thirty_one_day_and_leap_february_boundaries(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        // 31-day month accepted; a 30th-day end for it is rejected.
        $this->assertNull($this->rawInsert($tenant, '2026-01-01', '2026-01-31'));
        $this->assertSame('23514', $this->rawInsert($tenant, '2026-01-01', '2026-01-30'));

        // 2028 is a leap year: February ends on the 29th.
        $this->assertNull($this->rawInsert($tenant, '2028-02-01', '2028-02-29'));
        $this->assertSame('23514', $this->rawInsert($tenant, '2028-02-01', '2028-02-28'));
    }
}
