<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Requests\ReorderStatusRequest;
use App\Modules\Tasks\Http\Requests\StoreStatusRequest;
use App\Modules\Tasks\Http\Requests\UpdateStatusRequest;
use App\Modules\Tasks\Http\Resources\TaskStatusResource;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Services\TaskStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskStatusController extends Controller
{
    public function __construct(private readonly TaskStatusService $statuses) {}

    public function index(): JsonResponse
    {
        return TaskStatusResource::collection(
            TaskStatus::query()->orderBy('sort_order')->get()
        )->response();
    }

    public function store(StoreStatusRequest $request): JsonResponse
    {
        $status = $this->statuses->create($request->user(), $request->validated());

        return (new TaskStatusResource($status))->response()->setStatusCode(201);
    }

    public function update(UpdateStatusRequest $request, TaskStatus $status): JsonResponse
    {
        $status = $this->statuses->update($request->user(), $status, $request->validated());

        return (new TaskStatusResource($status))->response();
    }

    public function setDefault(Request $request, TaskStatus $status): JsonResponse
    {
        return (new TaskStatusResource($this->statuses->setDefault($request->user(), $status)))->response();
    }

    public function deactivate(Request $request, TaskStatus $status): JsonResponse
    {
        return (new TaskStatusResource($this->statuses->deactivate($request->user(), $status)))->response();
    }

    public function reactivate(Request $request, TaskStatus $status): JsonResponse
    {
        return (new TaskStatusResource($this->statuses->reactivate($request->user(), $status)))->response();
    }

    public function reorder(ReorderStatusRequest $request): JsonResponse
    {
        $this->statuses->reorder($request->user(), $request->validated()['ordered_ids']);

        return response()->json(['data' => ['ok' => true]]);
    }
}
