<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Modules\Tasks\Http\Requests\TaskAttachmentRequest;
use App\Modules\Tasks\Http\Resources\TaskAttachmentResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAttachment;
use App\Modules\Tasks\Services\TaskAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAttachmentController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(private readonly TaskAttachmentService $attachments) {}

    public function store(TaskAttachmentRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $data = $request->validated();
        $attachment = $this->attachments->store($request->user(), $task, $request->file('file'), $data['comment_id'] ?? null);

        return (new TaskAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Request $request, Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $attachment->task_id === (string) $task->getKey(), 404);

        return $this->attachments->download($attachment);
    }

    public function destroy(Request $request, Task $task, TaskAttachment $attachment): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $attachment->task_id === (string) $task->getKey(), 404);
        $this->attachments->delete($request->user(), $task, $attachment);

        return response()->json(['data' => ['ok' => true]]);
    }
}
