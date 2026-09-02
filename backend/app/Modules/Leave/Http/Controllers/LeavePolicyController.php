<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leave\Http\Requests\LeavePolicyAssignmentRequest;
use App\Modules\Leave\Http\Requests\LeavePolicyRequest;
use App\Modules\Leave\Http\Resources\LeavePolicyResource;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeavePolicyAssignment;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company-scoped CRUD for leave policies + scope assignments. */
class LeavePolicyController extends Controller
{
    public function __construct(
        private readonly LeavePolicyService $policies,
        private readonly LeavePolicyAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeavePolicy::query()->with('assignments')->orderBy('name');
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->query('leave_type_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return LeavePolicyResource::collection($query->get())->response();
    }

    public function store(LeavePolicyRequest $request): JsonResponse
    {
        $policy = $this->policies->create($request->validated(), $request->user());

        return (new LeavePolicyResource($policy))->response()->setStatusCode(201);
    }

    public function show(LeavePolicy $policy): JsonResponse
    {
        return (new LeavePolicyResource($policy->load('assignments')))->response();
    }

    public function update(LeavePolicyRequest $request, LeavePolicy $policy): JsonResponse
    {
        $policy = $this->policies->update($policy, $request->validated(), $request->user());

        return (new LeavePolicyResource($policy->load('assignments')))->response();
    }

    public function archive(Request $request, LeavePolicy $policy): JsonResponse
    {
        $policy = $this->policies->archive($policy, $request->user());

        return (new LeavePolicyResource($policy))->response();
    }

    public function assign(LeavePolicyAssignmentRequest $request, LeavePolicy $policy): JsonResponse
    {
        $this->assignments->assign($policy, $request->validated(), $request->user());

        return (new LeavePolicyResource($policy->fresh('assignments')))->response()->setStatusCode(201);
    }

    public function unassign(Request $request, LeavePolicy $policy, LeavePolicyAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->leave_policy_id === $policy->getKey(), 404);
        $this->assignments->unassign($assignment, $request->user());

        return (new LeavePolicyResource($policy->fresh('assignments')))->response();
    }
}
