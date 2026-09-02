<?php

namespace Tests\Feature\Tasks;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Models\TaskWatcher;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tasks\Services\TaskStatusService;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TaskSecurityTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function linked(Tenant $tenant, array $attrs = []): array
    {
        return $this->withinTenant($tenant, function () use ($attrs) {
            $u = User::factory()->create();
            TenantMembership::create(['user_id' => $u->id, 'status' => 'active']);
            $e = app(EmployeeService::class)->create(array_merge(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active'], $attrs));
            $e->fill(['user_id' => $u->id])->save();

            return [$u, $e->fresh()];
        });
    }

    private function statusId(string $key): string
    {
        return TaskStatus::query()->where('bootstrap_key', $key)->value('id');
    }

    public function test_team_scoped_assign_requires_matching_team_grant(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $teamA = $this->makeTeam($tenant);
        $teamB = $this->makeTeam($tenant);
        [$leadUser, $leadEmp] = $this->linked($tenant);
        [, $memberEmp] = $this->linked($tenant);

        $this->withinTenant($tenant, function () use ($owner, $teamA, $teamB, $leadUser, $memberEmp) {
            TeamMembership::create(['team_id' => $teamA->getKey(), 'employee_id' => $memberEmp->getKey(), 'role_in_team' => 'member']);
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'team', 'scope_id' => $teamA->getKey()]);
            $assign = app(TaskAssignmentService::class);

            // Grant scoped to the WRONG team → cannot assign.
            app(RoleAssignmentService::class)->assignBySlug($leadUser, 'team-leader', 'team', $teamB->getKey());
            try {
                $assign->assign($leadUser, $task->fresh(), $memberEmp->getKey(), false);
                $this->fail('wrong-team grant should not authorize assignment');
            } catch (ValidationException) {
            }

            // Grant scoped to the CORRECT team → may assign a team member.
            app(RoleAssignmentService::class)->assignBySlug($leadUser, 'team-leader', 'team', $teamA->getKey());
            $assignee = $assign->assign($leadUser, $task->fresh(), $memberEmp->getKey(), false);
            $this->assertSame($memberEmp->getKey(), $assignee->employee_id);
        });
    }

    public function test_standalone_scope_is_stable_across_assignment_changes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        $manager = $this->memberWithRole($tenant, 'department-manager', 'department', $dept->getKey());
        [, $emp] = $this->linked($tenant, ['department_id' => $dept->getKey()]);

        $this->withinTenant($tenant, function () use ($owner, $dept, $manager, $emp) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'department', 'scope_id' => $dept->getKey()]);
            $resolver = app(TaskVisibilityResolver::class);
            // Department manager sees it via scope (no assignees yet).
            $this->assertTrue($resolver->canViewTask($manager, $task->fresh()));

            // Assign then unassign — scope (hence visibility) is unchanged.
            $assign = app(TaskAssignmentService::class);
            $assign->assign($owner, $task->fresh(), $emp->getKey(), true);
            $assign->unassign($owner, $task->fresh(), $emp->getKey());
            $this->assertTrue($resolver->canViewTask($manager, $task->fresh()));
        });
    }

    public function test_status_category_locked_when_referenced_and_default_guard(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskStatusService::class);
            $todo = TaskStatus::query()->where('bootstrap_key', 'todo')->first();
            // Reference it by creating a task in that status.
            app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company', 'status_id' => $todo->getKey()]);

            // Category change on a referenced status is rejected; rename is allowed.
            try {
                $svc->update($owner, $todo->fresh(), ['category' => 'done']);
                $this->fail('referenced status category must be locked');
            } catch (ValidationException) {
            }
            $renamed = $svc->update($owner, $todo->fresh(), ['name' => 'Inbox']);
            $this->assertSame('Inbox', $renamed->name);

            // Deactivating the default promotes a replacement; the sole active default cannot be removed.
            foreach (['in_progress', 'blocked', 'done', 'cancelled'] as $k) {
                $svc->deactivate($owner, TaskStatus::query()->where('bootstrap_key', $k)->first());
            }
            $this->expectException(ValidationException::class);
            $svc->deactivate($owner, TaskStatus::query()->where('bootstrap_key', 'todo')->first());
        });
    }

    public function test_watcher_auto_watch_rules(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$assigneeUser, $assigneeEmp] = $this->linked($tenant);
        $unlinkedEmp = $this->withinTenant($tenant, fn () => app(EmployeeService::class)->create(['first_name' => 'No', 'last_name' => 'User', 'employment_status' => 'active']));

        $this->withinTenant($tenant, function () use ($owner, $assigneeUser, $assigneeEmp, $unlinkedEmp) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            // Creator auto-watched.
            $this->assertTrue(TaskWatcher::query()->where('task_id', $task->getKey())->where('user_id', $owner->id)->exists());

            $assign = app(TaskAssignmentService::class);
            $assign->assign($owner, $task->fresh(), $assigneeEmp->getKey(), true);
            // Linked assignee auto-watched.
            $this->assertTrue(TaskWatcher::query()->where('task_id', $task->getKey())->where('user_id', $assigneeUser->id)->exists());

            // Unlinked assignee: no fabricated watcher.
            $assign->assign($owner, $task->fresh(), $unlinkedEmp->getKey(), false);
            $this->assertSame(2, TaskWatcher::query()->where('task_id', $task->getKey())->count());
        });
    }
}
