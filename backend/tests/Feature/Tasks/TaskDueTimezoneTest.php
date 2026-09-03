<?php

namespace Tests\Feature\Tasks;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskReportService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tasks\Support\TaskDueQuery;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * BLOCKER 1: the single overdue/due-soon predicate (TaskDueQuery) must be
 * timezone-correct per row and must AGREE with the authoritative
 * Task::isOverdue() across every surface — management list, My Tasks, reports
 * overdue count, workload overdue count. Date-only deadlines use the row's IANA
 * due_timezone (local midnight after due_on), never the UTC session date.
 *
 * The overdue SQL compares against the live database now(), which the test cannot
 * freeze; assertions are therefore constructed to be deterministic regardless of
 * the wall-clock instant the suite runs at (see the extreme-offset pair below).
 */
class TaskDueTimezoneTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:User,1:string} acting user linked to an employee, holding company task authority */
    private function actorLinkedToEmployee(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, function () {
            $u = User::factory()->create();
            TenantMembership::create(['user_id' => $u->id, 'status' => 'active']);
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'A', 'employment_status' => 'active']);
            $e->fill(['user_id' => $u->id])->save();
            app(RoleAssignmentService::class)->assignBySlug($u, 'admin', 'company', null);

            return [$u, (string) $e->getKey()];
        });
    }

    public function test_overdue_predicate_is_timezone_correct_and_agrees_across_surfaces(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$actor, $empId] = $this->actorLinkedToEmployee($tenant);

        // Extreme IANA offsets that are ALWAYS on different calendar dates:
        // Kiritimati is UTC+14, Midway UTC-11 (25h apart, > one day), so a due_on
        // equal to "today in Midway" is always still open in Midway yet already
        // past in Kiritimati — a deterministic timezone flip with the SAME due_on.
        $midwayToday = CarbonImmutable::now('Pacific/Midway')->toDateString();

        $this->withinTenant($tenant, function () use ($owner, $actor, $empId, $midwayToday) {
            $doneId = TaskStatus::query()->where('bootstrap_key', 'done')->value('id');

            $make = function (array $attrs) use ($owner, $empId): Task {
                $task = app(TaskService::class)->create($owner, array_merge(['title' => 'T', 'scope_type' => 'company'], $attrs));
                app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $empId, false);

                return $task->fresh();
            };

            $tasks = [];
            // Same due_on, two zones → the timezone flip.
            $tasks['midway_open'] = $make(['due_type' => 'date', 'due_on' => $midwayToday, 'due_timezone' => 'Pacific/Midway']);
            $tasks['kiritimati_overdue'] = $make(['due_type' => 'date', 'due_on' => $midwayToday, 'due_timezone' => 'Pacific/Kiritimati']);
            // Clearly past / future date-only in ordinary zones (agreement battery).
            $tasks['hebron_past'] = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('Asia/Hebron')->subDays(10)->toDateString(), 'due_timezone' => 'Asia/Hebron']);
            $tasks['la_future'] = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('America/Los_Angeles')->addDays(10)->toDateString(), 'due_timezone' => 'America/Los_Angeles']);
            // DST-sensitive zone: the AT TIME ZONE conversion must pick the offset
            // in effect on that date; agreement proves it is handled like PHP.
            $tasks['la_dst_past'] = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('America/Los_Angeles')->subDays(120)->toDateString(), 'due_timezone' => 'America/Los_Angeles']);
            // Datetime regression: still the instant, unaffected by date-only logic.
            $tasks['dt_past'] = $make(['due_type' => 'datetime', 'due_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(), 'due_timezone' => 'UTC']);
            $tasks['dt_future'] = $make(['due_type' => 'datetime', 'due_at' => CarbonImmutable::now('UTC')->addDays(3)->toIso8601String(), 'due_timezone' => 'UTC']);
            // Terminal + archived are NEVER overdue even with a past due date.
            $tasks['terminal'] = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('UTC')->subDays(10)->toDateString(), 'due_timezone' => 'UTC', 'status_id' => $doneId]);
            $archived = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('UTC')->subDays(10)->toDateString(), 'due_timezone' => 'UTC']);
            app(TaskService::class)->archive($owner, $archived->fresh());
            $tasks['archived'] = $archived->fresh();

            // The deterministic timezone flip (same due_on, different overdue state).
            $this->assertFalse($tasks['midway_open']->fresh()->load('status')->isOverdue(), 'Midway task must still be open');
            $this->assertTrue($tasks['kiritimati_overdue']->fresh()->load('status')->isOverdue(), 'Kiritimati task with the same due_on must be overdue');

            // Terminal / archived never overdue.
            $this->assertFalse($tasks['terminal']->fresh()->load('status')->isOverdue());
            $this->assertFalse($tasks['archived']->fresh()->load('status')->isOverdue());

            // Authoritative overdue set (per Task::isOverdue()).
            $expected = collect($tasks)
                ->filter(fn (Task $t) => $t->fresh()->load('status')->isOverdue())
                ->map(fn (Task $t) => (string) $t->getKey())
                ->sort()->values()->all();

            // Surface 1 — management list overdue filter (same predicate as the controller).
            $indexIds = app(TaskVisibilityResolver::class)->visibleTaskQuery($actor)
                ->whereRaw(TaskDueQuery::overdue())->pluck('id')
                ->map(fn ($id) => (string) $id)->sort()->values()->all();
            $this->assertSame($expected, $indexIds, 'management overdue filter disagrees with Task::isOverdue()');

            // Surface 2 — My Tasks overdue section (same predicate as applySection).
            $meIds = Task::query()
                ->whereIn('id', TaskAssignee::query()->where('employee_id', $empId)->select('task_id'))
                ->whereRaw(TaskDueQuery::overdue())->pluck('id')
                ->map(fn ($id) => (string) $id)->sort()->values()->all();
            $this->assertSame($expected, $meIds, 'My Tasks overdue disagrees with Task::isOverdue()');

            // Surface 3 — reports overdue count.
            $reports = app(TaskReportService::class);
            $this->assertSame(count($expected), $reports->overdueCount($actor), 'reports overdue count disagrees');

            // Surface 4 — workload overdue count (single assignee → one row).
            $workload = collect($reports->workload($actor))->firstWhere('employee_id', $empId);
            $this->assertNotNull($workload);
            $this->assertSame(count($expected), (int) $workload['overdue'], 'workload overdue count disagrees');
        });
    }

    public function test_due_soon_is_timezone_correct_and_excludes_overdue_and_terminal(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$actor, $empId] = $this->actorLinkedToEmployee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $actor, $empId) {
            $doneId = TaskStatus::query()->where('bootstrap_key', 'done')->value('id');

            $make = function (array $attrs) use ($owner, $empId): Task {
                $task = app(TaskService::class)->create($owner, array_merge(['title' => 'T', 'scope_type' => 'company'], $attrs));
                app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $empId, false);

                return $task->fresh();
            };

            // Within the 7-day window (per its own timezone) → counts as due-soon.
            $soon = $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('Asia/Hebron')->addDays(2)->toDateString(), 'due_timezone' => 'Asia/Hebron']);
            $soonDt = $make(['due_type' => 'datetime', 'due_at' => CarbonImmutable::now('UTC')->addDay()->toIso8601String(), 'due_timezone' => 'UTC']);
            // Beyond the window → not due-soon.
            $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('Asia/Hebron')->addDays(30)->toDateString(), 'due_timezone' => 'Asia/Hebron']);
            // Already overdue → not due-soon (mutually exclusive).
            $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('UTC')->subDays(3)->toDateString(), 'due_timezone' => 'UTC']);
            // Terminal within the window → excluded.
            $make(['due_type' => 'date', 'due_on' => CarbonImmutable::now('Asia/Hebron')->addDays(2)->toDateString(), 'due_timezone' => 'Asia/Hebron', 'status_id' => $doneId]);

            $workload = collect(app(TaskReportService::class)->workload($actor))->firstWhere('employee_id', $empId);
            $this->assertNotNull($workload);
            // Exactly the two open, within-window tasks.
            $this->assertSame(2, (int) $workload['due_soon']);

            // Direct predicate sanity: the two due-soon tasks are exactly those matched.
            $dueSoonIds = Task::query()->whereRaw(TaskDueQuery::dueSoon())->pluck('id')
                ->map(fn ($id) => (string) $id)->sort()->values()->all();
            $this->assertSame(
                collect([$soon, $soonDt])->map(fn (Task $t) => (string) $t->getKey())->sort()->values()->all(),
                $dueSoonIds,
            );
        });
    }
}
