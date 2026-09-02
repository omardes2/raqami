<?php

namespace Tests\Feature\Tasks;

use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskAttachmentService;
use App\Modules\Tasks\Services\TaskBoardService;
use App\Modules\Tasks\Services\TaskChecklistService;
use App\Modules\Tasks\Services\TaskCommentService;
use App\Modules\Tasks\Services\TaskReportService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TaskCollaborationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function member(Tenant $tenant): User
    {
        $u = User::factory()->create();
        $this->withinTenant($tenant, fn () => TenantMembership::create(['user_id' => $u->id, 'status' => 'active']));

        return $u;
    }

    private function statusId(string $key): string
    {
        return TaskStatus::query()->where('bootstrap_key', $key)->value('id');
    }

    public function test_comment_idempotency_and_payload_mismatch(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $svc = app(TaskCommentService::class);
            $c1 = $svc->create($owner, $task, 'Hello', [], 'creq-1');
            $c2 = $svc->create($owner, $task, 'Hello', [], 'creq-1');
            $this->assertSame($c1->getKey(), $c2->getKey());

            $this->expectException(ConflictHttpException::class);
            $svc->create($owner, $task, 'Different body', [], 'creq-1');
        });
    }

    public function test_mention_of_user_without_visibility_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $outsider = $this->member($tenant); // member with no task visibility

        $this->withinTenant($tenant, function () use ($owner, $outsider) {
            // Standalone company task; outsider has no tasks.view grant → not visible.
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $this->expectException(ValidationException::class);
            app(TaskCommentService::class)->create($owner, $task, 'hi @x', [$outsider->id]);
        });
    }

    public function test_comment_edit_stale_version_conflicts(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $svc = app(TaskCommentService::class);
            $c = $svc->create($owner, $task, 'Hello');
            $this->expectException(ConflictHttpException::class);
            $svc->edit($owner, $task, $c, 'edited', 999);
        });
    }

    public function test_checklist_toggle_and_kanban_move(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $project = app(ProjectService::class)->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            $t1 = app(TaskService::class)->create($owner, ['title' => 'A', 'project_id' => $project->getKey()]);
            $t2 = app(TaskService::class)->create($owner, ['title' => 'B', 'project_id' => $project->getKey()]);

            // Checklist toggle.
            $item = app(TaskChecklistService::class)->add($owner, $t1->fresh(), 'step 1');
            $done = app(TaskChecklistService::class)->toggle($owner, $t1->fresh(), $item, true);
            $this->assertTrue($done->is_completed);

            // Move t2 above t1 in the in_progress column.
            $board = app(TaskBoardService::class);
            $moved = $board->move($owner, $t2->fresh(), $this->statusId('in_progress'), null, $t1->getKey());
            $this->assertNotNull($moved->board_rank);
            $this->assertSame('in_progress', $moved->fresh()->status->category->value);
        });
    }

    public function test_attachment_comment_must_belong_to_same_task(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $taskA = app(TaskService::class)->create($owner, ['title' => 'A', 'scope_type' => 'company']);
            $taskB = app(TaskService::class)->create($owner, ['title' => 'B', 'scope_type' => 'company']);
            $commentOnA = app(TaskCommentService::class)->create($owner, $taskA, 'on A');

            $file = UploadedFile::fake()->create('x.pdf', 10);
            $this->expectException(ValidationException::class);
            // Attaching a comment from Task A onto an upload against Task B must fail.
            app(TaskAttachmentService::class)
                ->store($owner, $taskB, $file, $commentOnA->getKey());
        });
    }

    public function test_project_progress_excludes_cancelled_and_subtasks(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $project = app(ProjectService::class)->create($owner, ['name' => 'P', 'scope_type' => 'company']);
            $svc = app(TaskService::class);
            $a = $svc->create($owner, ['title' => 'A', 'project_id' => $project->getKey()]);
            $b = $svc->create($owner, ['title' => 'B', 'project_id' => $project->getKey()]);
            $c = $svc->create($owner, ['title' => 'C', 'project_id' => $project->getKey()]);
            $svc->changeStatus($owner, $a->fresh(), $this->statusId('done'), $a->version);        // done
            $svc->changeStatus($owner, $c->fresh(), $this->statusId('cancelled'), $c->version);    // excluded
            // b stays open. done=1, open=1 → 0.5
            $this->assertSame(0.5, app(TaskReportService::class)->projectProgress($project->fresh()));
        });
    }
}
