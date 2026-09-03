<?php

namespace Tests\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Support for payroll tests that must run finalization at transaction level 0 (the
 * real fail-closed REPEATABLE READ path) and therefore CANNOT use RefreshDatabase.
 * Such tests COMMIT real tenants/runs, so this trait tears them down afterwards to
 * avoid leaking global rows into other tests. The finalized/closed immutability
 * triggers would block the cascade delete, so they are disabled for the owner-run
 * cleanup only and immediately restored.
 */
trait CommitsPayrollAtTopLevel
{
    /** @var array<int, string> */
    protected array $committedTenantIds = [];

    /**
     * createCompanyWithOwner + record the tenant for cleanup.
     *
     * @return array{0:User,1:Tenant}
     */
    protected function trackedCompany(array $companyData = [], array $userAttributes = []): array
    {
        [$owner, $tenant] = $this->createCompanyWithOwner($companyData, $userAttributes);
        $this->committedTenantIds[] = (string) $tenant->id;

        return [$owner, $tenant];
    }

    protected function tearDown(): void
    {
        if ($this->committedTenantIds !== [] && DB::getDriverName() === 'pgsql') {
            $tables = ['payroll_entry_lines', 'payroll_entries', 'payroll_adjustments', 'payroll_runs', 'payroll_periods'];
            foreach ($tables as $t) {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            }
            try {
                DB::table('tenants')->whereIn('id', $this->committedTenantIds)->delete();
            } finally {
                foreach ($tables as $t) {
                    DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
                }
            }
            $this->committedTenantIds = [];
        }

        parent::tearDown();
    }
}
