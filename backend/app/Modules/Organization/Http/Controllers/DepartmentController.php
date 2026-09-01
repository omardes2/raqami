<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Http\Requests\DepartmentRequest;
use App\Modules\Organization\Http\Resources\DepartmentResource;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Department::query()->withCount('employees')->orderBy('name');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        return response()->json(
            DepartmentResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)))->response()->getData(true)
        );
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = $this->service->create($request->validated(), $request->user());

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department->loadCount('employees'));
    }

    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        $department = $this->service->update($department, $request->validated(), $request->user());

        return new DepartmentResource($department);
    }

    public function archive(Request $request, Department $department, AuditLogger $audit): JsonResponse
    {
        $active = Employee::query()->where('department_id', $department->id)
            ->whereNotIn('employment_status', ['terminated', 'archived'])->count();
        if ($active > 0) {
            return response()->json(['message' => __('organization.department_has_employees')], 422);
        }

        $department->update(['status' => 'archived']);
        $audit->log('department.archived', ['actor' => $request->user(), 'subject' => $department]);

        return response()->json(['id' => $department->id, 'status' => $department->status]);
    }
}
