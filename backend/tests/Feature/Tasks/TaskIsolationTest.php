<?php

namespace Tests\Feature\Tasks;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Services\ProjectMembershipService;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskAttachmentService;
use App\Modules\Tasks\Services\TaskChecklistService;
use App\Modules\Tasks\Services\TaskCommentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Proves PostgreSQL RLS — not just the app scope — isolates EVERY Sprint 6 task
 * table across tenants via RAW SQL, that platform read-only cannot write, and
 * that task_activity_events is append-only.
 */
class TaskIsolationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const TABLES = [
        'projects', 'project_memberships', 'task_statuses', 'tasks', 'task_assignees',
        'task_checklist_items', 'task_comments', 'task_comment_mentions',
        'task_watchers', 'task_attachments', 'task_activity_events',
    ];

    private function seedTenant(Tenant $tenant, $owner): void
    {
        Storage::fake();
        $this->withinTenant($tenant, function () use ($owner) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Iso', 'last_name' => 'Task', 'employment_status' => 'active']);
            $project = app(ProjectService::class)->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            app(ProjectMembershipService::class)->add($owner, $project, $employee->getKey(), ProjectMembershipRole::Member);

            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'project_id' => $project->getKey()]);
            app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $employee->getKey(), true);
            app(TaskChecklistService::class)->add($owner, $task->fresh(), 'step');
            $comment = app(TaskCommentService::class)->create($owner, $task->fresh(), 'hello', [$owner->id]);
            app(TaskAttachmentService::class)->store($owner, $task->fresh(), UploadedFile::fake()->create('a.pdf', 3), $comment->getKey());
        });
    }

    public function test_every_task_table_is_tenant_isolated_by_rls(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA, $ownerA);

        $this->withinTenant($tenantA, function () {
            foreach (self::TABLES as $table) {
                $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} should have tenant A rows");
            }
        });

        // Tenant B (raw SQL) sees NONE of tenant A's rows — even when it explicitly
        // filters by tenant A's id. (Each tenant legitimately has its OWN statuses,
        // so a bare count is not zero; the RLS guarantee is cross-tenant invisibility.)
        $tenantAId = $tenantA->getKey();
        $this->withinTenant($tenantB, function () use ($tenantAId) {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, DB::table($table)->where('tenant_id', $tenantAId)->count(), "{$table} leaked into tenant B");
            }
        });
    }

    public function test_platform_readonly_cannot_write_task_tables(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA, $ownerA);

        $affected = app(TenantContext::class)->runAsPlatform(
            fn () => DB::table('tasks')->update(['title' => 'tampered'])
        );
        $this->assertSame(0, $affected, 'platform read-only must not write task rows');
    }

    public function test_task_activity_events_is_append_only(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $this->seedTenant($tenantA, $ownerA);

        $this->withinTenant($tenantA, function () {
            $id = DB::table('task_activity_events')->value('id');
            $this->assertNotNull($id);
            // No UPDATE/DELETE policy → RLS exposes zero rows to mutation (0 affected).
            $this->assertSame(0, DB::table('task_activity_events')->where('id', $id)->update(['event_type' => 'x']));
            $this->assertSame(0, DB::table('task_activity_events')->where('id', $id)->delete());
        });
    }
}
