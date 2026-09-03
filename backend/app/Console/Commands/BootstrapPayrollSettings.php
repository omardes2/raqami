<?php

namespace App\Console\Commands;

use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Backfill entry point that ensures a payroll_settings row exists for existing
 * tenants receiving Sprint 7 after deployment. Idempotent (one row per tenant);
 * safe to re-run. New tenants are bootstrapped during onboarding — no scheduler.
 *
 *   payroll:bootstrap-settings            # all tenants
 *   payroll:bootstrap-settings --tenant=  # a single tenant id
 */
class BootstrapPayrollSettings extends Command
{
    protected $signature = 'payroll:bootstrap-settings {--tenant= : Restrict to a single tenant id}';

    protected $description = 'Ensure a payroll settings row exists for existing tenants (idempotent).';

    public function handle(PayrollSettingsService $settings, TenantContext $context): int
    {
        $query = Tenant::query();
        if ($this->option('tenant')) {
            $query->whereKey($this->option('tenant'));
        }

        $count = 0;
        $errors = 0;
        $query->orderBy('id')->chunkById(100, function ($tenants) use ($settings, $context, &$count, &$errors) {
            foreach ($tenants as $tenant) {
                try {
                    $context->runAs($tenant, fn () => $settings->getOrCreate());
                    $count++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Tenant {$tenant->getKey()}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Payroll settings bootstrap complete: tenants={$count} errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
