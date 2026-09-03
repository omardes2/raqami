<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Http\Requests\EndEffectiveRequest;
use App\Modules\Payroll\Http\Requests\StoreEmployeeCompensationRequest;
use App\Modules\Payroll\Http\Resources\EmployeeCompensationResource;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeCompensationController extends Controller
{
    public function __construct(
        private readonly EmployeeCompensationService $compensations,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.view');

        return EmployeeCompensationResource::collection(
            $this->compensations->history((string) $employee->getKey())
        )->response();
    }

    public function store(StoreEmployeeCompensationRequest $request, Employee $employee): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.manage');

        $row = $this->compensations->create($request->user(), (string) $employee->getKey(), $request->validated());

        return (new EmployeeCompensationResource($row))->response()->setStatusCode(201);
    }

    public function end(EndEffectiveRequest $request, EmployeeCompensation $compensation): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.manage');

        $row = $this->compensations->end($request->user(), $compensation, $request->validated()['effective_to']);

        return (new EmployeeCompensationResource($row))->response();
    }
}
