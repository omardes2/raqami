<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Modules\Tasks\Http\Requests\ProjectMemberRequest;
use App\Modules\Tasks\Http\Requests\StoreProjectRequest;
use App\Modules\Tasks\Http\Requests\UpdateProjectRequest;
use App\Modules\Tasks\Http\Resources\ProjectMembershipResource;
use App\Modules\Tasks\Http\Resources\ProjectResource;
use App\Modules\Tasks\Http\Resources\TaskListResource;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\ProjectMembershipService;
use App\Modules\Tasks\Services\ProjectService;
use App\Modules\Tasks\Services\TaskReportService;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectMembershipService $members,
        private readonly TaskReportService $reports,
        private readonly TaskVisibilityResolver $visibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->visibility->visibleProjectQuery($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->has('archived'), fn ($q) => $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('created_at');

        return ProjectResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        )->response();
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $project->load('memberships');
        $project->progress = $this->reports->projectProgress($project);

        return (new ProjectResource($project))->response();
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $data = $request->validated();
        $project = $this->projects->update($request->user(), $project, $data, $data['expected_version'] ?? null);

        return (new ProjectResource($project))->response();
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $project = $this->projects->archive($request->user(), $project, $request->integer('expected_version') ?: null);

        return (new ProjectResource($project))->response();
    }

    public function unarchive(Request $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $project = $this->projects->unarchive($request->user(), $project, $request->integer('expected_version') ?: null);

        return (new ProjectResource($project))->response();
    }

    /** Kanban board payload: visible non-archived tasks in the project, ordered by rank. */
    public function board(Request $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $tasks = Task::query()
            ->where('project_id', $project->getKey())
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->with(['status', 'assignees'])
            ->orderBy('status_id')->orderBy('board_rank')->orderBy('id')
            ->get();

        return TaskListResource::collection($tasks)->response();
    }

    public function members(Request $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);

        return ProjectMembershipResource::collection($project->memberships()->get())->response();
    }

    public function addMember(ProjectMemberRequest $request, Project $project): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $data = $request->validated();
        $membership = $this->members->add(
            $request->user(), $project, $data['employee_id'], ProjectMembershipRole::from($data['role']),
        );

        return (new ProjectMembershipResource($membership))->response()->setStatusCode(201);
    }

    public function updateMemberRole(ProjectMemberRequest $request, Project $project, string $employee): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $membership = $this->members->changeRole(
            $request->user(), $project, $employee, ProjectMembershipRole::from($request->validated()['role']),
        );

        return (new ProjectMembershipResource($membership))->response();
    }

    public function removeMember(Request $request, Project $project, string $employee): JsonResponse
    {
        $this->visibleProjectOr404($request->user(), $project);
        $this->members->remove($request->user(), $project, $employee);

        return response()->json(['data' => ['ok' => true]]);
    }
}
