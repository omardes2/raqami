<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Modules\Tasks\Http\Requests\StoreCommentRequest;
use App\Modules\Tasks\Http\Requests\UpdateCommentRequest;
use App\Modules\Tasks\Http\Resources\TaskCommentResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Tasks\Services\TaskCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(private readonly TaskCommentService $comments) {}

    public function index(Request $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $comments = $task->comments()->with('mentions')->orderBy('created_at')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return TaskCommentResource::collection($comments)->response();
    }

    public function store(StoreCommentRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $comment = $this->comments->create(
            $request->user(), $task, $data['body'], $data['mentions'] ?? [], $data['client_request_id'] ?? null,
        );

        return (new TaskCommentResource($comment->load('mentions')))->response()->setStatusCode(201);
    }

    public function update(UpdateCommentRequest $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $comment->task_id === (string) $task->getKey(), 404);
        $data = $request->validated();
        $comment = $this->comments->edit($request->user(), $task, $comment, $data['body'], $data['expected_version'] ?? null);

        return (new TaskCommentResource($comment))->response();
    }

    public function destroy(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $comment->task_id === (string) $task->getKey(), 404);
        $this->comments->delete($request->user(), $task, $comment, $request->integer('expected_version') ?: null);

        return response()->json(['data' => ['ok' => true]]);
    }
}
