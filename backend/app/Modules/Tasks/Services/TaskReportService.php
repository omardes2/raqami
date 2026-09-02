<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Derived, visibility-safe task reporting (§40). EVERY aggregate is built from
 * TaskVisibilityResolver::visibleTaskQuery so members_only counts never leak.
 * Workload is transparent derived data — active/high/overdue/estimate/due-soon —
 * NOT a performance or disciplinary score. done/cancelled/archived are excluded
 * from workload and overdue.
 */
class TaskReportService
{
    /** Pragmatic aggregate-overdue (per-row timezone is applied by Task::isOverdue). */
    private const OVERDUE_SQL = "((tasks.due_type = 'datetime' AND tasks.due_at < now()) OR (tasks.due_type = 'date' AND tasks.due_on < current_date))";

    public function __construct(private readonly TaskVisibilityResolver $visibility) {}

    /** @return array<string, int> category => count (non-archived visible tasks) */
    public function summaryByStatus(User $user): array
    {
        return $this->visibleActive($user, includeTerminal: true)
            ->join('task_statuses', 'tasks.status_id', '=', 'task_statuses.id')
            ->selectRaw('task_statuses.category as category, count(*) as c')
            ->groupBy('task_statuses.category')
            ->pluck('c', 'category')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, int> priority => count (open visible tasks) */
    public function summaryByPriority(User $user): array
    {
        return $this->visibleActive($user)
            ->selectRaw('priority, count(*) as c')
            ->groupBy('priority')
            ->pluck('c', 'priority')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function overdueCount(User $user): int
    {
        return $this->visibleActive($user)->whereRaw(self::OVERDUE_SQL)->count();
    }

    /**
     * Transparent per-employee workload over visible open tasks.
     *
     * @return array<int, array{employee_id:string, active:int, high_urgent:int, overdue:int, estimated_minutes:int, due_soon:int}>
     */
    public function workload(User $user): array
    {
        $visibleOpenIds = $this->visibleActive($user)->select('tasks.id');

        return DB::table('task_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->whereIn('task_assignees.task_id', $visibleOpenIds)
            ->groupBy('task_assignees.employee_id')
            ->selectRaw('task_assignees.employee_id as employee_id')
            ->selectRaw('count(*) as active')
            ->selectRaw("sum(case when tasks.priority in ('high','urgent') then 1 else 0 end) as high_urgent")
            ->selectRaw('sum(case when '.self::OVERDUE_SQL.' then 1 else 0 end) as overdue')
            ->selectRaw('coalesce(sum(tasks.estimated_minutes), 0) as estimated_minutes')
            ->selectRaw("sum(case when tasks.due_type = 'date' and tasks.due_on <= (current_date + integer '7') then 1 when tasks.due_type = 'datetime' and tasks.due_at <= (now() + interval '7 days') then 1 else 0 end) as due_soon")
            ->get()
            ->map(fn ($r) => [
                'employee_id' => (string) $r->employee_id,
                'active' => (int) $r->active,
                'high_urgent' => (int) $r->high_urgent,
                'overdue' => (int) $r->overdue,
                'estimated_minutes' => (int) $r->estimated_minutes,
                'due_soon' => (int) $r->due_soon,
            ])
            ->all();
    }

    /**
     * Truthful project progress: done ÷ (done + open) over top-level, non-archived
     * tasks; cancelled and subtasks excluded. Empty project → null (documented).
     */
    public function projectProgress(Project $project): ?float
    {
        $base = fn () => Task::query()
            ->where('project_id', $project->getKey())
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->join('task_statuses', 'tasks.status_id', '=', 'task_statuses.id');

        $done = (clone $base())->where('task_statuses.category', 'done')->count();
        $open = (clone $base())->whereIn('task_statuses.category', ['backlog', 'todo', 'in_progress', 'blocked'])->count();
        $denominator = $done + $open;

        return $denominator === 0 ? null : round($done / $denominator, 4);
    }

    /** Visible tasks, non-archived; open (non-terminal) unless includeTerminal. */
    private function visibleActive(User $user, bool $includeTerminal = false): Builder
    {
        $query = $this->visibility->visibleTaskQuery($user)->whereNull('tasks.archived_at');
        if (! $includeTerminal) {
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('task_statuses')
                    ->whereColumn('task_statuses.id', 'tasks.status_id')
                    ->whereNotIn('task_statuses.category', ['done', 'cancelled']);
            });
        }

        return $query;
    }
}
