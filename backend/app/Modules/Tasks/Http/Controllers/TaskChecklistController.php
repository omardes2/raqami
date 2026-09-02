<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Modules\Tasks\Http\Requests\ChecklistItemRequest;
use App\Modules\Tasks\Http\Resources\TaskChecklistItemResource;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskChecklistItem;
use App\Modules\Tasks\Services\TaskChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskChecklistController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(private readonly TaskChecklistService $checklist) {}

    public function store(ChecklistItemRequest $request, Task $task): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        $item = $this->checklist->add($request->user(), $task, (string) $request->validated()['text']);

        return (new TaskChecklistItemResource($item))->response()->setStatusCode(201);
    }

    public function update(ChecklistItemRequest $request, Task $task, TaskChecklistItem $item): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $item->task_id === (string) $task->getKey(), 404);
        $data = $request->validated();
        if (array_key_exists('is_completed', $data)) {
            $item = $this->checklist->toggle($request->user(), $task, $item, (bool) $data['is_completed']);
        }
        if (array_key_exists('text', $data) || array_key_exists('sort_order', $data)) {
            $item = $this->checklist->update($request->user(), $task, $item, $data);
        }

        return (new TaskChecklistItemResource($item))->response();
    }

    public function destroy(Request $request, Task $task, TaskChecklistItem $item): JsonResponse
    {
        $this->visibleTaskOr404($request->user(), $task);
        abort_unless((string) $item->task_id === (string) $task->getKey(), 404);
        $this->checklist->remove($request->user(), $task, $item);

        return response()->json(['data' => ['ok' => true]]);
    }
}
