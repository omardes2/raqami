<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\EntitlementService;
use App\Modules\Employees\Http\Requests\EmployeeStatusRequest;
use App\Modules\Employees\Http\Requests\EmployeeStoreRequest;
use App\Modules\Employees\Http\Requests\EmployeeUpdateRequest;
use App\Modules\Employees\Http\Resources\EmployeeListResource;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Services\EmployeeTransferService;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    use AuthorizesEmployeeScope;

    /** Whitelisted sort columns — prevents arbitrary/unsafe sort injection. */
    private const SORTABLE = [
        'employee_number', 'first_name', 'last_name', 'hire_date',
        'employment_status', 'created_at',
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeScopeResolver $scope,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with(['branch', 'department', 'jobTitle', 'manager']);

        // Organizational scope enforcement (prevents cross-scope listing/IDOR).
        $this->scope->applyScope($query, $request->user(), 'employees.view');

        // Filters
        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('employee_number', 'ilike', "%{$s}%")
                    ->orWhere('first_name', 'ilike', "%{$s}%")
                    ->orWhere('last_name', 'ilike', "%{$s}%")
                    ->orWhere('display_name', 'ilike', "%{$s}%")
                    ->orWhere('work_email', 'ilike', "%{$s}%");
            });
        }
        foreach (['branch_id', 'department_id', 'job_title_id', 'employment_status', 'employment_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($teamId = $request->query('team_id')) {
            $query->whereHas('teamMemberships', fn ($q) => $q->where('team_id', $teamId));
        }

        // Safe sorting
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        $perPage = min((int) $request->query('per_page', 20), 100);
        $page = $query->paginate($perPage);

        return response()->json(
            EmployeeListResource::collection($page)->response()->getData(true)
        );
    }

    public function store(EmployeeStoreRequest $request): JsonResponse
    {
        // Plan entitlement: reject creation once the plan's employee cap is
        // reached (billing logic lives in EntitlementService, not here).
        $this->entitlements->assertCanAddEmployee();

        $data = $request->validated();

        // Scoped creators may only place employees within their branch/department.
        if (! $this->scope->canPlaceInScope($request->user(), 'employees.create', $data['branch_id'] ?? null, $data['department_id'] ?? null)) {
            abort(403, __('employees.out_of_scope_placement'));
        }

        $employee = $this->employees->create($data, $request->user());

        if (! empty($data['team_ids'])) {
            app(EmployeeTransferService::class)->apply($employee, ['team_ids' => $data['team_ids']], $request->user());
        }

        return (new EmployeeResource($employee->fresh(['branch', 'department', 'jobTitle', 'manager', 'teams'])))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');

        return new EmployeeResource(
            $employee->load(['branch', 'department', 'jobTitle', 'manager', 'teams'])
        );
    }

    public function update(EmployeeUpdateRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        $employee = $this->employees->update($employee, $request->validated(), $request->user());

        return new EmployeeResource($employee->load(['branch', 'department', 'jobTitle', 'manager', 'teams']));
    }

    public function changeStatus(EmployeeStatusRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        $employee = $this->employees->changeStatus(
            $employee,
            $request->string('employment_status'),
            $request->input('reason'),
            $request->user(),
        );

        return new EmployeeResource($employee);
    }

    public function archive(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.archive');
        $this->employees->archive($employee, $request->user());

        return response()->json(['id' => $employee->id, 'status' => 'archived']);
    }
}
