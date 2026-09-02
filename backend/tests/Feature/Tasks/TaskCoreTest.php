<?php

namespace Tests\Feature\Tasks;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TaskCoreTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)->create(array_merge(
            ['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active'], $attrs,
        )));
    }

    private function statusId(string $bootstrapKey): string
    {
        return TaskStatus::query()->where('bootstrap_key', $bootstrapKey)->value('id');
    }

    public function test_standalone_create_requires_scope_and_is_idempotent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskService::class);
            $task = $svc->create($owner, [
                'title' => 'Write report', 'scope_type' => 'company', 'client_request_id' => 'req-1',
            ]);
            $this->assertSame('company', $task->scope_type->value);
            $this->assertNull($task->project_id);

            // Same key + same payload → same row.
            $again = $svc->create($owner, [
                'title' => 'Write report', 'scope_type' => 'company', 'client_request_id' => 'req-1',
            ]);
            $this->assertSame($task->getKey(), $again->getKey());
            $this->assertSame(1, Task::query()->count());

            // Same key + DIFFERENT payload → 409.
            $this->expectException(ConflictHttpException::class);
            $svc->create($owner, [
                'title' => 'Different title', 'scope_type' => 'company', 'client_request_id' => 'req-1',
            ]);
        });
    }

    public function test_done_sets_completed_and_cancelled_does_not(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskService::class);
            $task = $svc->create($owner, ['title' => 'T', 'scope_type' => 'company']);

            $done = $svc->changeStatus($owner, $task->fresh(), $this->statusId('done'), $task->version);
            $this->assertNotNull($done->completed_at);

            $reopened = $svc->changeStatus($owner, $done->fresh(), $this->statusId('todo'), $done->version);
            $this->assertNull($reopened->completed_at);

            $cancelled = $svc->changeStatus($owner, $reopened->fresh(), $this->statusId('cancelled'), $reopened->version);
            $this->assertNull($cancelled->completed_at); // cancelled is not successful completion
        });
    }

    public function test_stale_version_change_returns_conflict(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskService::class);
            $task = $svc->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $this->expectException(ConflictHttpException::class);
            $svc->update($owner, $task->fresh(), ['title' => 'New'], 999);
        });
    }

    public function test_date_only_overdue_uses_timezone_end_of_day(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $task = app(TaskService::class)->create($owner, [
                'title' => 'Due today', 'scope_type' => 'company',
                'due_type' => 'date', 'due_on' => '2026-09-10', 'due_timezone' => 'Asia/Amman',
            ]);
            // During the local day → not overdue.
            $this->assertFalse($task->isOverdue(CarbonImmutable::parse('2026-09-10T20:00:00', 'Asia/Amman')->utc()));
            // After end of local day → overdue.
            $this->assertTrue($task->isOverdue(CarbonImmutable::parse('2026-09-11T00:30:00', 'Asia/Amman')->utc()));
        });
    }

    public function test_subtask_one_level_only(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskService::class);
            $parent = $svc->create($owner, ['title' => 'Parent', 'scope_type' => 'company']);
            $child = $svc->create($owner, ['title' => 'Child', 'scope_type' => 'company', 'parent_task_id' => $parent->getKey()]);
            $this->assertSame($parent->getKey(), $child->parent_task_id);
            $this->assertNull($child->board_rank); // subtasks never ranked

            // A grandchild (subtask of a subtask) is rejected.
            $this->expectException(ValidationException::class);
            $svc->create($owner, ['title' => 'GC', 'scope_type' => 'company', 'parent_task_id' => $child->getKey()]);
        });
    }

    public function test_multiple_assignees_single_primary_and_swap(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $e1 = $this->employee($tenant);
        $e2 = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $e1, $e2) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $assign = app(TaskAssignmentService::class);
            $assign->assign($owner, $task->fresh(), $e1->getKey(), true);
            $assign->assign($owner, $task->fresh(), $e2->getKey(), false);
            $this->assertSame(2, TaskAssignee::query()->where('task_id', $task->getKey())->count());
            $this->assertSame(1, TaskAssignee::query()->where('task_id', $task->getKey())->where('is_primary', true)->count());

            // Promote e2 to primary → e1 demoted.
            $assign->assign($owner, $task->fresh(), $e2->getKey(), true);
            $this->assertTrue(TaskAssignee::query()->where('task_id', $task->getKey())->where('employee_id', $e2->getKey())->value('is_primary'));
            $this->assertFalse(TaskAssignee::query()->where('task_id', $task->getKey())->where('employee_id', $e1->getKey())->value('is_primary'));
        });
    }

    public function test_inactive_employee_cannot_be_newly_assigned(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $inactive = $this->employee($tenant, ['employment_status' => 'terminated']);

        $this->withinTenant($tenant, function () use ($owner, $inactive) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $this->expectException(ValidationException::class);
            app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $inactive->getKey(), false);
        });
    }

    public function test_project_task_inherits_scope_and_auto_watches_creator(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $project = app(ProjectService::class)->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            $task = app(TaskService::class)->create($owner, ['title' => 'PT', 'project_id' => $project->getKey()]);
            $this->assertSame($project->getKey(), $task->project_id);
            $this->assertNull($task->scope_type); // inherited, not duplicated
            $this->assertNotNull($task->board_rank); // top-level project task is ranked
            $this->assertSame(1, $task->watchers()->where('user_id', $owner->id)->count());
        });
    }
}
