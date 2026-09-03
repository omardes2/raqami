<?php

namespace Tests\Feature\Tasks;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Services\ProjectMembershipService;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskReportService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * BLOCKER 2: the members_only ceiling must hold on WRITE as well as read.
 *  (2A) A non-member holding ordinary scoped tasks.create over the project scope
 *       must NOT create a task inside a members_only project — scope-safe 404.
 *  (2B) Creator identity is not an ACL: a former member who created a task but is
 *       no longer a member (and not assigned) can no longer see it.
 *  (2C) The assignee exception stays narrow (the assigned task only).
 *  (2D) An org-scope holder cannot infer hidden members_only counts via reports.
 */
class TaskMembersOnlyWriteTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:User,1:Employee} */
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

    /** Build a members_only project in a department, plus a dept-scoped non-member holding tasks.create. */
    private function membersOnlyProjectWithOutsider(): array
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        // Department manager scope covers the project scope AND holds tasks.create,
        // but the user is NOT a member of the members_only project.
        $outsider = $this->memberWithRole($tenant, 'department-manager', 'department', $dept->getKey());

        $project = $this->withinTenant($tenant, fn () => app(ProjectService::class)->create($owner, [
            'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'members_only',
        ]));

        return [$owner, $tenant, $dept, $outsider, $project];
    }

    public function test_dept_scoped_non_member_cannot_create_in_members_only_project(): void
    {
        [, $tenant,, $outsider, $project] = $this->membersOnlyProjectWithOutsider();

        $this->withinTenant($tenant, function () use ($outsider, $project) {
            // Scope-safe 404 (NotFound), NOT a 422/403 that would confirm existence.
            $this->expectException(NotFoundHttpException::class);
            app(TaskService::class)->create($outsider, ['title' => 'Injected', 'project_id' => $project->getKey()]);
        });
    }

    public function test_dept_scoped_non_member_cannot_fetch_project_detail(): void
    {
        [, $tenant,, $outsider, $project] = $this->membersOnlyProjectWithOutsider();

        $this->actingAs($outsider)->getJson("/api/projects/{$project->getKey()}", $this->tenantHeaders($tenant))
            ->assertStatus(404);
    }

    public function test_member_with_create_may_create_in_members_only_project(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        [$memberUser, $memberEmp] = $this->linked($tenant, ['department_id' => $dept->getKey()]);
        // Member additionally holds scoped tasks.create (dept manager role).
        $this->withinTenant($tenant, fn () => app(RoleAssignmentService::class)->assignBySlug($memberUser, 'department-manager', 'department', $dept->getKey()));

        $this->withinTenant($tenant, function () use ($owner, $dept, $memberUser, $memberEmp) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'members_only',
            ]);
            app(ProjectMembershipService::class)->add($owner, $project, $memberEmp->getKey(), ProjectMembershipRole::Member);

            $task = app(TaskService::class)->create($memberUser, ['title' => 'Legit', 'project_id' => $project->getKey()]);
            $this->assertSame((string) $project->getKey(), (string) $task->project_id);
        });
    }

    public function test_project_local_manager_may_create(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$mgrUser, $mgrEmp] = $this->linked($tenant);

        $this->withinTenant($tenant, function () use ($owner, $mgrUser, $mgrEmp) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'company', 'visibility' => 'members_only',
            ]);
            // Project-local MANAGER membership (no broad grant).
            app(ProjectMembershipService::class)->add($owner, $project, $mgrEmp->getKey(), ProjectMembershipRole::Manager);

            $task = app(TaskService::class)->create($mgrUser, ['title' => 'Mgr task', 'project_id' => $project->getKey()]);
            $this->assertSame((string) $project->getKey(), (string) $task->project_id);
        });
    }

    public function test_company_authority_may_create(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'company', 'visibility' => 'members_only',
            ]);
            // Owner is company authority — may create even without a membership row.
            $task = app(TaskService::class)->create($owner, ['title' => 'Owner task', 'project_id' => $project->getKey()]);
            $this->assertSame((string) $project->getKey(), (string) $task->project_id);
        });
    }

    public function test_former_member_creator_loses_visibility_of_their_project_task(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        [$memberUser, $memberEmp] = $this->linked($tenant, ['department_id' => $dept->getKey()]);
        $this->withinTenant($tenant, fn () => app(RoleAssignmentService::class)->assignBySlug($memberUser, 'department-manager', 'department', $dept->getKey()));

        $this->withinTenant($tenant, function () use ($owner, $dept, $memberUser, $memberEmp) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'members_only',
            ]);
            app(ProjectMembershipService::class)->add($owner, $project, $memberEmp->getKey(), ProjectMembershipRole::Member);

            // Member creates a task, then loses membership and is not assigned.
            $task = app(TaskService::class)->create($memberUser, ['title' => 'X', 'project_id' => $project->getKey()]);
            $resolver = app(TaskVisibilityResolver::class);
            $this->assertTrue($resolver->canViewTask($memberUser, $task->fresh()->load('project')));

            app(ProjectMembershipService::class)->remove($owner, $project, $memberEmp->getKey());

            // created_by_user_id is NOT an ACL: the task is now invisible.
            $this->assertFalse($resolver->canViewTask($memberUser, $task->fresh()->load('project')));
            $this->assertFalse($resolver->visibleTaskQuery($memberUser)->whereKey($task->getKey())->exists());
        });
    }

    public function test_former_member_still_assigned_sees_only_that_task_not_the_board(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        [$memberUser, $memberEmp] = $this->linked($tenant, ['department_id' => $dept->getKey()]);

        $this->withinTenant($tenant, function () use ($owner, $dept, $memberUser, $memberEmp) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'members_only',
            ]);
            app(ProjectMembershipService::class)->add($owner, $project, $memberEmp->getKey(), ProjectMembershipRole::Member);

            // Owner creates a task in the project and assigns it to the member.
            $assigned = app(TaskService::class)->create($owner, ['title' => 'Assigned', 'project_id' => $project->getKey()]);
            $other = app(TaskService::class)->create($owner, ['title' => 'Other', 'project_id' => $project->getKey()]);
            app(TaskAssignmentService::class)->assign($owner, $assigned->fresh(), $memberEmp->getKey(), true);

            // Membership removed; the direct assignment remains.
            app(ProjectMembershipService::class)->remove($owner, $project, $memberEmp->getKey());

            $resolver = app(TaskVisibilityResolver::class);
            // Sees the assigned task ONLY (narrow assignee exception)…
            $this->assertTrue($resolver->canViewTask($memberUser, $assigned->fresh()->load('project')));
            // …but not the sibling task, the project, or the board.
            $this->assertFalse($resolver->canViewTask($memberUser, $other->fresh()->load('project')));
            $this->assertFalse($resolver->canViewProject($memberUser, $project->fresh()));
            $this->assertFalse($resolver->visibleProjectQuery($memberUser)->whereKey($project->getKey())->exists());
        });
    }

    public function test_org_scope_holder_cannot_infer_hidden_members_only_counts(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        // Reports viewer scoped to the department (tasks.reports.view + scoped view),
        // but NOT a member of the members_only project.
        $viewer = $this->memberWithRole($tenant, 'department-manager', 'department', $dept->getKey());

        $this->withinTenant($tenant, function () use ($owner, $dept, $viewer) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'members_only',
            ]);
            // Create hidden tasks inside the members_only project.
            app(TaskService::class)->create($owner, ['title' => 'Hidden 1', 'project_id' => $project->getKey()]);
            app(TaskService::class)->create($owner, ['title' => 'Hidden 2', 'project_id' => $project->getKey()]);

            // The non-member reports viewer's aggregates never see the hidden tasks.
            $reports = app(TaskReportService::class);
            $this->assertSame([], $reports->summaryByStatus($viewer));
            $this->assertSame(0, array_sum($reports->summaryByStatus($viewer)));
            $this->assertSame([], $reports->workload($viewer));
            // Sprint 8A: completion rate is built on the same visibility base, so a
            // hidden members_only project must leave it null (no visible tasks).
            $this->assertNull($reports->completionRate($viewer));
        });
    }
}
