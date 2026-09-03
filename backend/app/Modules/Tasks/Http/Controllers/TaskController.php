<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Requests\TaskAssignRequest;
use App\Modules\Tasks\Http\Requests\TaskRankRequest;
use App\Modules\Tasks\Http\Requests\TaskStatusChangeRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskRequest;
use App\Modules\Tasks\Http\Resources\TaskActivityResource;
use App\Modules\Tasks\Http\Resources\TaskListResource;
use App\Modules\Tasks\Http\Resources\TaskResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskActivityEvent;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskBoardService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tasks\Services\TaskWatcherService;
use App\Modules\Tasks\Support\TaskDueQuery;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskAssignmentService $assignments,
        private readonly TaskBoardService $board,
        private readonly TaskWatcherService $watchers,
        private readonly TaskVisibilityResolver $visibility,
    ) {}

    /** Management task list (scoped + paginated + filtered). */
    public function index(Request $request): JsonResponse
    {
        $query = $this->visibility->visibleTaskQuery($request->user())
            ->with(['status', 'assignees'])
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->query('status_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->query('priority')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->query('project_id')))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereRaw(TaskDueQuery::overdue()))
            ->when($request->has('archived'), fn ($q) => $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('created_at');

        return TaskListResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        )->response();
    }

    /** Employee "My Tasks": tasks assigned to the acting employee. */
    public function me(Request $request): JsonResponse
    {
        $employeeId = $this->visibility->actorEmployeeId($request->user());
        if ($employeeId === null) {
            return TaskListResource::collection([])->response();
        }
        $query = Task::query()
            ->whereIn('id', TaskAssignee::query()->where('employee_id', $employeeId)->select('task_id'))
            ->with(['status', 'assignees'])
            ->when($request->filled('section'), fn ($q) => $this->applySection($q, $request->query('section')))
            ->orderBy('due_on')->orderByDesc('created_at');

        return TaskListResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        )->response();
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);

        return (new TaskResource($task->load(['status', 'assignees', 'checklistItems', 'attachments', 'subtasks'])))->response();
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->tasks->create($request->user(), $request->validated());

        return (new TaskResource($task->load(['status', 'assignees'])))->response()->setStatusCode(201);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $task = $this->tasks->update($request->user(), $task, $data, $data['expected_version'] ?? null);

        return (new TaskResource($task->load(['status', 'assignees'])))->response();
    }

    public function status(TaskStatusChangeRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $task = $this->tasks->changeStatus($request->user(), $task, $data['status_id'], $data['expected_version'] ?? null);

        return (new TaskResource($task->load('status')))->response();
    }

    public function assign(TaskAssignRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $this->assignments->assign($request->user(), $task, $data['employee_id'], (bool) ($data['is_primary'] ?? false));

        return (new TaskResource($task->fresh()->load('assignees')))->response();
    }

    public function unassign(Request $request, Task $task, string $employee): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $this->assignments->unassign($request->user(), $task, $employee);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function archive(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $task = $this->tasks->archive($request->user(), $task, $request->integer('expected_version') ?: null);

        return (new TaskResource($task))->response();
    }

    public function unarchive(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $task = $this->tasks->unarchive($request->user(), $task, $request->integer('expected_version') ?: null);

        return (new TaskResource($task))->response();
    }

    public function rank(TaskRankRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $task = $this->board->move(
            $request->user(), $task, $data['status_id'],
            $data['after_task_id'] ?? null, $data['before_task_id'] ?? null, $data['expected_version'] ?? null,
        );

        return (new TaskResource($task->load('status')))->response();
    }

    public function watch(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $this->watchers->watch($request->user(), $task);

        return response()->json(['data' => ['watching' => true]]);
    }

    public function unwatch(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $this->watchers->unwatch($request->user(), $task);

        return response()->json(['data' => ['watching' => false]]);
    }

    public function activity(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $events = TaskActivityEvent::query()
            ->where('task_id', $task->getKey())
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return TaskActivityResource::collection($events)->response();
    }

    private function applySection($query, string $section)
    {
        return match ($section) {
            'overdue' => $query->whereRaw(TaskDueQuery::overdue()),
            'completed' => $query->whereNotNull('completed_at'),
            'today' => $query->whereDate('due_on', now()->toDateString())->whereNull('archived_at'),
            'upcoming' => $query->whereNotNull('due_on')->whereDate('due_on', '>', now()->toDateString())->whereNull('completed_at')->whereNull('archived_at'),
            default => $query,
        };
    }
}
