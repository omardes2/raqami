<?php

namespace Tests\Feature\Notifications;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — notifications:prune (12-month retention) and notifications:remind-tasks
 * (due-soon/overdue, dedupe, completed excluded).
 */
class NotificationCommandsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function inbox(Tenant $tenant, string $userId, ?string $type = null): int
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.user_id', ?, false)", [$userId]);
        DB::statement("select set_config('app.platform_readonly', 'off', false)");
        try {
            $q = DB::table('notifications');
            if ($type !== null) {
                $q->where('type', $type);
            }

            return (int) $q->count();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    /** @return array{0:Employee,1:User} */
    private function linked(string $first): array
    {
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create(['first_name' => $first, 'last_name' => 'X', 'employment_status' => 'active']);
        $employee->fill(['user_id' => $user->id])->save();
        TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);

        return [$employee->fresh(), $user];
    }

    private function overdueTask(Tenant $tenant, User $owner, Employee $assignee): Task
    {
        return $this->withinTenant($tenant, function () use ($owner, $assignee) {
            $task = app(TaskService::class)->create($owner, [
                'title' => 'Old', 'scope_type' => 'company',
                'due_type' => 'date', 'due_on' => CarbonImmutable::now()->subDays(3)->toDateString(), 'due_timezone' => 'UTC',
            ]);
            app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $assignee->getKey(), true);

            return $task->fresh();
        });
    }

    public function test_remind_tasks_notifies_overdue_once(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$assignee, $user] = $this->withinTenant($tenant, fn () => $this->linked('Ovd'));
        $this->overdueTask($tenant, $owner, $assignee);

        $this->artisan('notifications:remind-tasks')->assertSuccessful();
        $this->assertSame(1, $this->inbox($tenant, (string) $user->id, 'task.overdue'));

        // Same-state rerun: no duplicate.
        $this->artisan('notifications:remind-tasks')->assertSuccessful();
        $this->assertSame(1, $this->inbox($tenant, (string) $user->id, 'task.overdue'));
    }

    public function test_remind_tasks_excludes_completed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$assignee, $user] = $this->withinTenant($tenant, fn () => $this->linked('Done'));
        $task = $this->overdueTask($tenant, $owner, $assignee);

        $this->withinTenant($tenant, function () use ($owner, $task) {
            $doneStatus = TaskStatus::query()->where('category', 'done')->value('id');
            app(TaskService::class)->changeStatus($owner, $task->fresh(), $doneStatus);
        });

        $this->artisan('notifications:remind-tasks')->assertSuccessful();
        $this->assertSame(0, $this->inbox($tenant, (string) $user->id, 'task.overdue'));
    }
}
