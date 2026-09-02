<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leave\Http\Requests\LeaveTypeRequest;
use App\Modules\Leave\Http\Resources\LeaveTypeResource;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company-scoped CRUD for leave types (archive over delete). */
class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveTypeService $types) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeaveType::query()->orderBy('name');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return LeaveTypeResource::collection($query->get())->response();
    }

    public function store(LeaveTypeRequest $request): JsonResponse
    {
        $type = $this->types->create($request->validated(), $request->user());

        return (new LeaveTypeResource($type))->response()->setStatusCode(201);
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return (new LeaveTypeResource($leaveType))->response();
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $type = $this->types->update($leaveType, $request->validated(), $request->user());

        return (new LeaveTypeResource($type))->response();
    }

    public function archive(Request $request, LeaveType $leaveType): JsonResponse
    {
        $type = $this->types->archive($leaveType, $request->user());

        return (new LeaveTypeResource($type))->response();
    }
}
