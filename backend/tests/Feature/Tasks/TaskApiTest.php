<?php

namespace Tests\Feature\Tasks;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * HTTP surface for Tasks: create/show/status, self-service, permission gating,
 * and scope-safe 404 (cross-tenant + intra-tenant invisibility).
 */
class TaskApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function linkedEmployeeUser(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, function () {
            $u = User::factory()->create();
            TenantMembership::create(['user_id' => $u->id, 'status' => 'active']);
            $e = app(EmployeeService::class)->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active']);
            $e->fill(['user_id' => $u->id])->save();

            return [$u, $e->fresh()];
        });
    }

    public function test_owner_creates_task_and_reads_it(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $created = $this->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Ship it', 'scope_type' => 'company',
        ], $this->tenantHeaders($tenant))->assertStatus(201)->json('id');

        $this->actingAs($owner)->getJson("/api/tasks/{$created}", $this->tenantHeaders($tenant))
            ->assertOk()->assertJsonPath('title', 'Ship it');
    }

    public function test_assignee_changes_status_without_manage_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$empUser, $employee] = $this->linkedEmployeeUser($tenant);

        $taskId = $this->withinTenant($tenant, function () use ($owner, $employee) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            app(TaskAssignmentService::class)->assign($owner, $task, $employee->getKey(), true);

            return $task->getKey();
        });
        $inProgress = $this->withinTenant($tenant, fn () => TaskStatus::query()->where('bootstrap_key', 'in_progress')->value('id'));

        // The assignee (no tasks.manage) may move their own task's status.
        $this->actingAs($empUser)->postJson("/api/tasks/{$taskId}/status", [
            'status_id' => $inProgress,
        ], $this->tenantHeaders($tenant))->assertOk()->assertJsonPath('status_category', 'in_progress');
    }

    public function test_task_index_requires_tasks_view_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$empUser] = $this->linkedEmployeeUser($tenant);

        // Employee (participation only) has no tasks.view → 403 on the management list.
        $this->actingAs($empUser)->getJson('/api/tasks', $this->tenantHeaders($tenant))
            ->assertStatus(403);
        // But My Tasks is reachable.
        $this->actingAs($empUser)->getJson('/api/tasks/me', $this->tenantHeaders($tenant))
            ->assertOk();
    }

    public function test_cross_tenant_task_is_not_found(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();

        $taskId = $this->withinTenant($tenantA, fn () => app(TaskService::class)
            ->create($ownerA, ['title' => 'A secret', 'scope_type' => 'company'])->getKey());

        // Owner B (different tenant) cannot see tenant A's task.
        $this->actingAs($ownerB)->getJson("/api/tasks/{$taskId}", $this->tenantHeaders($tenantB))
            ->assertStatus(404);
    }

    public function test_project_crud_and_statuses_index(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $projectId = $this->actingAs($owner)->postJson('/api/projects', [
            'name' => 'Launch', 'scope_type' => 'company',
        ], $this->tenantHeaders($tenant))->assertStatus(201)->json('id');

        $this->actingAs($owner)->getJson("/api/projects/{$projectId}", $this->tenantHeaders($tenant))
            ->assertOk()->assertJsonPath('name', 'Launch');

        $this->actingAs($owner)->getJson('/api/task-statuses', $this->tenantHeaders($tenant))
            ->assertOk()->assertJsonCount(5, 'data');
    }
}
