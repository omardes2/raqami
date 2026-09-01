<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Http\Requests\BranchRequest;
use App\Modules\Organization\Http\Resources\BranchResource;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()->withCount('employees')->orderBy('name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        return response()->json(
            BranchResource::collection($query->paginate($this->perPage($request)))->response()->getData(true)
        );
    }

    public function store(BranchRequest $request, AuditLogger $audit): JsonResponse
    {
        $branch = Branch::create($request->validated());
        $audit->log('branch.created', ['actor' => $request->user(), 'subject' => $branch,
            'metadata' => ['name' => $branch->name, 'code' => $branch->code]]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch->loadCount('employees'));
    }

    public function update(BranchRequest $request, Branch $branch, AuditLogger $audit): BranchResource
    {
        $branch->update($request->validated());
        $audit->log('branch.updated', ['actor' => $request->user(), 'subject' => $branch,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new BranchResource($branch);
    }

    public function archive(Request $request, Branch $branch, AuditLogger $audit): JsonResponse
    {
        $activeEmployees = Employee::query()->where('branch_id', $branch->id)
            ->whereNotIn('employment_status', ['terminated', 'archived'])->count();
        if ($activeEmployees > 0) {
            return response()->json(['message' => __('organization.branch_has_employees')], 422);
        }

        $branch->update(['status' => 'archived']);
        $audit->log('branch.archived', ['actor' => $request->user(), 'subject' => $branch]);

        return response()->json(['id' => $branch->id, 'status' => $branch->status]);
    }

    private function perPage(Request $request): int
    {
        return min((int) $request->query('per_page', 20), 100);
    }
}
