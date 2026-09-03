<?php

namespace App\Console\Commands;

use App\Modules\Tasks\Services\TaskStatusBootstrapService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * One-time/backfill entry point that seeds the default task status catalog for
 * existing tenants receiving Sprint 6 after deployment. Idempotent (bootstrap_key);
 * safe to re-run. New tenants are bootstrapped during onboarding — no scheduler.
 *
 *   tasks:bootstrap-statuses            # all tenants
 *   tasks:bootstrap-statuses --tenant=  # a single tenant id
 */
class BootstrapTaskStatuses extends Command
{
    protected $signature = 'tasks:bootstrap-statuses {--tenant= : Restrict to a single tenant id}';

    protected $description = 'Seed the default task status catalog for existing tenants (idempotent).';

    public function handle(TaskStatusBootstrapService $bootstrap): int
    {
        $query = Tenant::query();
        if ($this->option('tenant')) {
            $query->whereKey($this->option('tenant'));
        }

        $count = 0;
        $errors = 0;
        $query->orderBy('id')->chunkById(100, function ($tenants) use ($bootstrap, &$count, &$errors) {
            foreach ($tenants as $tenant) {
                try {
                    $bootstrap->bootstrap($tenant);
                    $count++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Tenant {$tenant->getKey()}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Task status bootstrap complete: tenants={$count} errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
