<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Enums\TaskStatusCategory;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\TaskStatusBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Default task status catalog is bootstrapped at onboarding, idempotent by the
 * immutable bootstrap_key, and preserves tenant customization on re-run.
 */
class TaskStatusBootstrapTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_onboarding_seeds_five_defaults_with_one_active_default(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $statuses = TaskStatus::query()->orderBy('sort_order')->get();
            $this->assertCount(5, $statuses);
            $this->assertSame(
                ['todo', 'in_progress', 'blocked', 'done', 'cancelled'],
                $statuses->pluck('bootstrap_key')->all(),
            );
            // Exactly one active default, and it is the todo status.
            $defaults = $statuses->where('is_default', true)->where('active', true);
            $this->assertCount(1, $defaults);
            $this->assertSame('todo', $defaults->first()->bootstrap_key);
            // Categories are the fixed semantic truth.
            $this->assertSame(TaskStatusCategory::Done, $statuses->firstWhere('bootstrap_key', 'done')->category);
            $this->assertSame(TaskStatusCategory::Cancelled, $statuses->firstWhere('bootstrap_key', 'cancelled')->category);
        });
    }

    public function test_bootstrap_is_idempotent_and_preserves_customization(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            // Tenant renames + recolors the "todo" status.
            $todo = TaskStatus::query()->where('bootstrap_key', 'todo')->first();
            $todo->forceFill(['name' => 'Backlog Item', 'code' => 'custom_todo', 'color' => '#111111'])->save();

            // Re-run bootstrap (existing-tenant backfill path).
            app(TaskStatusBootstrapService::class)->seed();

            $this->assertSame(5, TaskStatus::query()->count()); // no duplicates
            $todo->refresh();
            $this->assertSame('Backlog Item', $todo->name); // customization preserved
            $this->assertSame('custom_todo', $todo->code);
        });
    }
}
