<?php

namespace Tests\Feature\Tasks;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Services\ProjectMembershipService;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ProjectServiceTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Link a fresh user to a fresh employee inside the tenant; return [user, employee]. */
    private function linkedEmployee(Tenant $tenant, array $attrs = []): array
    {
        return $this->withinTenant($tenant, function () use ($attrs) {
            $user = User::factory()->create();
            $employee = app(EmployeeService::class)->create(array_merge(
                ['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active'],
                $attrs,
            ));
            $employee->fill(['user_id' => $user->id])->save();

            return [$user, $employee->fresh()];
        });
    }

    public function test_owner_creates_scoped_project_and_can_see_it(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Website Redesign', 'scope_type' => 'company',
            ]);
            $this->assertSame('active', $project->status->value);
            $this->assertNull($project->scope_id);
            $this->assertTrue(app(TaskVisibilityResolver::class)->canViewProject($owner, $project));
        });
    }

    public function test_cross_tenant_scope_target_is_rejected(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $branchB = $this->makeBranch($tenantB);

        $this->withinTenant($tenantA, function () use ($ownerA, $branchB) {
            $this->expectException(ValidationException::class);
            app(ProjectService::class)->create($ownerA, [
                'name' => 'X', 'scope_type' => 'branch', 'scope_id' => $branchB->getKey(),
            ]);
        });
    }

    public function test_members_only_hidden_from_org_scope_but_visible_to_member(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        // A department manager scoped to that department (scoped tasks.view/manage).
        $manager = $this->memberWithRole($tenant, 'department-manager', 'department', $dept->getKey());
        [$memberUser, $memberEmployee] = $this->linkedEmployee($tenant, ['department_id' => $dept->getKey()]);

        $project = $this->withinTenant($tenant, function () use ($owner, $dept, $memberEmployee) {
            $project = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'department', 'scope_id' => $dept->getKey(),
                'visibility' => 'members_only',
            ]);
            app(ProjectMembershipService::class)->add($owner, $project, $memberEmployee->getKey(), ProjectMembershipRole::Member);

            return $project;
        });

        $this->withinTenant($tenant, function () use ($manager, $memberUser, $project) {
            $resolver = app(TaskVisibilityResolver::class);
            // Department manager (org scope) WITHOUT membership cannot see members_only.
            $this->assertFalse($resolver->canViewProject($manager, $project));
            $this->assertFalse($resolver->visibleProjectQuery($manager)->whereKey($project->getKey())->exists());
            // The explicit member can.
            $this->assertTrue($resolver->canViewProject($memberUser, $project));
            $this->assertTrue($resolver->visibleProjectQuery($memberUser)->whereKey($project->getKey())->exists());
        });
    }

    public function test_project_local_manager_cannot_govern_membership(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$mgrUser, $mgrEmployee] = $this->linkedEmployee($tenant);
        [$targetUser, $targetEmployee] = $this->linkedEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $mgrUser, $mgrEmployee, $targetEmployee) {
            $project = app(ProjectService::class)->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            // mgr is a project-local MANAGER member (no global projects.manage).
            app(ProjectMembershipService::class)->add($owner, $project, $mgrEmployee->getKey(), ProjectMembershipRole::Manager);

            // Local manager cannot add members (governance).
            $this->expectException(ValidationException::class);
            app(ProjectMembershipService::class)->add($mgrUser, $project, $targetEmployee->getKey(), ProjectMembershipRole::Member);
        });
    }

    public function test_archive_blocks_and_unarchive_restores(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(ProjectService::class);
            $project = $svc->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            $svc->archive($owner, $project->fresh());
            $this->assertTrue(Project::query()->find($project->getKey())->isArchived());

            // Re-archive refused.
            try {
                $svc->archive($owner, $project->fresh());
                $this->fail('expected re-archive to fail');
            } catch (ValidationException) {
            }

            $svc->unarchive($owner, $project->fresh());
            $this->assertFalse(Project::query()->find($project->getKey())->isArchived());
        });
    }
}
