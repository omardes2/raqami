<?php

namespace Tests\Feature\Tasks;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskBoardService;
use App\Modules\Tasks\Services\TaskCommentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tasks\Services\TaskStatusService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Focused hardening (H1–H4):
 *  H1 intra-tenant scope-safe 404 (knows the ULID, lacks visibility → 404 not 403).
 *  H2 Kanban gap-exhaustion renormalization (single column, deterministic, bigint).
 *  H3 default-status concurrency gracefulness (one active default; no raw 500).
 *  H4 idempotent create unique-race gracefulness (task + comment): reuse or 409.
 */
class TaskHardeningTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function plainMember(Tenant $tenant): User
    {
        return $this->withinTenant($tenant, function () {
            $u = User::factory()->create();
            TenantMembership::create(['user_id' => $u->id, 'status' => 'active']);
            $e = app(EmployeeService::class)->create(['first_name' => 'M', 'last_name' => 'M', 'employment_status' => 'active']);
            $e->fill(['user_id' => $u->id])->save();

            return $u;
        });
    }

    // ---- H1: scope-safe 404 ---------------------------------------------------

    public function test_intra_tenant_invisible_task_is_404_not_403(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $dept = $this->makeDepartment($tenant);
        $outsider = $this->plainMember($tenant); // same tenant, no covering scope

        [$scopedTaskId, $membersOnlyTaskId] = $this->withinTenant($tenant, function () use ($owner, $dept) {
            // A scoped (non-members_only) project task the outsider does not cover.
            $scopedProject = app(ProjectService::class)->create($owner, [
                'name' => 'Scoped', 'scope_type' => 'department', 'scope_id' => $dept->getKey(), 'visibility' => 'scoped',
            ]);
            $scopedTask = app(TaskService::class)->create($owner, ['title' => 'S', 'project_id' => $scopedProject->getKey()]);

            // A members_only project task.
            $secret = app(ProjectService::class)->create($owner, [
                'name' => 'Secret', 'scope_type' => 'company', 'visibility' => 'members_only',
            ]);
            $secretTask = app(TaskService::class)->create($owner, ['title' => 'X', 'project_id' => $secret->getKey()]);

            return [$scopedTask->getKey(), $secretTask->getKey()];
        });

        // Knows the ULID, but lacks visibility → 404 (never 403, which would confirm existence).
        $this->actingAs($outsider)->getJson("/api/tasks/{$scopedTaskId}", $this->tenantHeaders($tenant))->assertStatus(404);
        $this->actingAs($outsider)->getJson("/api/tasks/{$membersOnlyTaskId}", $this->tenantHeaders($tenant))->assertStatus(404);
    }

    // ---- H2: Kanban renormalization ------------------------------------------

    public function test_gap_exhaustion_renormalizes_only_the_target_column(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $todo = TaskStatus::query()->where('bootstrap_key', 'todo')->value('id');
            $inProgress = TaskStatus::query()->where('bootstrap_key', 'in_progress')->value('id');

            $projectA = app(ProjectService::class)->create($owner, ['name' => 'A', 'scope_type' => 'company']);
            $projectB = app(ProjectService::class)->create($owner, ['name' => 'B', 'scope_type' => 'company']);

            $mk = fn ($project, $status, $title) => app(TaskService::class)->create($owner, [
                'title' => $title, 'project_id' => $project->getKey(), 'status_id' => $status,
            ]);

            // Target column: project A / todo — three top-level cards.
            $a = $mk($projectA, $todo, 'a');
            $b = $mk($projectA, $todo, 'b');
            $c = $mk($projectA, $todo, 'c');
            // Untouched columns: A/in_progress and B/todo.
            $otherStatus = $mk($projectA, $inProgress, 'x');
            $otherProject = $mk($projectB, $todo, 'y');

            // Force gap exhaustion: a and b become adjacent (gap of 1); c above them.
            $a->forceFill(['board_rank' => 1000])->save();
            $b->forceFill(['board_rank' => 1001])->save();
            $c->forceFill(['board_rank' => 500000])->save();
            $otherStatus->forceFill(['board_rank' => 4242])->save();
            $otherProject->forceFill(['board_rank' => 4243])->save();

            // Move c between a and b → no integer gap → synchronous renormalization.
            app(TaskBoardService::class)->move($owner, $c->fresh(), $todo, $a->getKey(), $b->getKey());

            // Only the A/todo column was renormalized to clean multiples of the gap.
            $aRank = (int) Task::query()->whereKey($a->getKey())->value('board_rank');
            $bRank = (int) Task::query()->whereKey($b->getKey())->value('board_rank');
            $cRank = (int) Task::query()->whereKey($c->getKey())->value('board_rank');
            $this->assertSame(65536, $aRank);
            $this->assertSame(131072, $bRank);
            // c landed strictly between a and b, deterministically ordered.
            $this->assertGreaterThan($aRank, $cRank);
            $this->assertLessThan($bRank, $cRank);
            // All ranks are positive and comfortably within bigint range.
            foreach ([$aRank, $bRank, $cRank] as $r) {
                $this->assertGreaterThan(0, $r);
                $this->assertLessThan(9223372036854775807, $r);
            }

            // Other columns were NOT touched (renormalization is single-column).
            $this->assertSame(4242, (int) Task::query()->whereKey($otherStatus->getKey())->value('board_rank'));
            $this->assertSame(4243, (int) Task::query()->whereKey($otherProject->getKey())->value('board_rank'));
        });
    }

    // ---- H3: default-status concurrency --------------------------------------

    public function test_default_status_swaps_keep_exactly_one_active_default(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(TaskStatusService::class);
            $todo = TaskStatus::query()->where('bootstrap_key', 'todo')->first();
            $inProgress = TaskStatus::query()->where('bootstrap_key', 'in_progress')->first();

            // Serialized swaps (the advisory lock reduces concurrent calls to this)
            // never break the invariant or raise.
            $svc->setDefault($owner, $todo);
            $svc->setDefault($owner, $inProgress);
            $svc->setDefault($owner, $todo);

            $this->assertSame(1, TaskStatus::query()->where('is_default', true)->where('active', true)->count());
            $this->assertTrue(TaskStatus::query()->whereKey($todo->getKey())->value('is_default'));
        });
    }

    // ---- H4: idempotent create unique-race -----------------------------------

    public function test_task_idempotency_reuse_and_conflict(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $key = (string) Str::ulid();
            $first = app(TaskService::class)->create($owner, ['title' => 'Once', 'scope_type' => 'company', 'client_request_id' => $key]);
            // Same key + same payload → reuse the same row.
            $again = app(TaskService::class)->create($owner, ['title' => 'Once', 'scope_type' => 'company', 'client_request_id' => $key]);
            $this->assertSame((string) $first->getKey(), (string) $again->getKey());
            $this->assertSame(1, Task::query()->where('client_request_id', $key)->count());

            // Same key + different payload → 409.
            $this->expectException(ConflictHttpException::class);
            app(TaskService::class)->create($owner, ['title' => 'Different', 'scope_type' => 'company', 'client_request_id' => $key]);
        });
    }

    public function test_comment_idempotency_reuse_and_conflict(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $svc = app(TaskCommentService::class);
            $key = (string) Str::ulid();

            $first = $svc->create($owner, $task->fresh(), 'hello', [], $key);
            $again = $svc->create($owner, $task->fresh(), 'hello', [], $key);
            $this->assertSame((string) $first->getKey(), (string) $again->getKey());
            $this->assertSame(1, TaskComment::query()->where('client_request_id', $key)->count());

            $this->expectException(ConflictHttpException::class);
            $svc->create($owner, $task->fresh(), 'different body', [], $key);
        });
    }
}
