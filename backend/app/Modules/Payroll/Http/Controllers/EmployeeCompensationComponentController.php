<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Http\Requests\EndEffectiveRequest;
use App\Modules\Payroll\Http\Requests\StoreEmployeeComponentRequest;
use App\Modules\Payroll\Http\Resources\EmployeeCompensationComponentResource;
use App\Modules\Payroll\Models\EmployeeCompensationComponent;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeCompensationComponentController extends Controller
{
    public function __construct(
        private readonly EmployeeCompensationComponentService $components,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.view');

        return EmployeeCompensationComponentResource::collection(
            $this->components->list((string) $employee->getKey())
        )->response();
    }

    public function store(StoreEmployeeComponentRequest $request, Employee $employee): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.manage');

        $row = $this->components->assign($request->user(), (string) $employee->getKey(), $request->validated());

        return (new EmployeeCompensationComponentResource($row))->response()->setStatusCode(201);
    }

    public function end(EndEffectiveRequest $request, EmployeeCompensationComponent $assignment): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.manage');

        $row = $this->components->end($request->user(), $assignment, $request->validated()['effective_to']);

        return (new EmployeeCompensationComponentResource($row))->response();
    }
}
