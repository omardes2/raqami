<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Http\Requests\EmployeeContractRequest;
use App\Modules\Employees\Http\Resources\EmployeeContractResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Contract foundation. NO compensation/payroll logic (ADR-014).
class EmployeeContractController extends Controller
{
    use AuthorizesEmployeeScope;

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        $contracts = $employee->contracts()->orderByDesc('start_date')->get();

        return response()->json(['data' => EmployeeContractResource::collection($contracts)]);
    }

    public function store(EmployeeContractRequest $request, Employee $employee, AuditLogger $audit): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        $contract = $employee->contracts()->create($request->validated());
        $audit->log('employee_contract.created', ['actor' => $request->user(), 'subject' => $contract,
            'metadata' => ['employee_id' => $employee->id, 'contract_number' => $contract->contract_number]]);

        return (new EmployeeContractResource($contract))->response()->setStatusCode(201);
    }

    public function update(EmployeeContractRequest $request, Employee $employee, EmployeeContract $contract, AuditLogger $audit): EmployeeContractResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        abort_unless($contract->employee_id === $employee->id, 404);

        $contract->update($request->validated());
        $audit->log('employee_contract.updated', ['actor' => $request->user(), 'subject' => $contract,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new EmployeeContractResource($contract);
    }

    public function archive(Request $request, Employee $employee, EmployeeContract $contract, AuditLogger $audit): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        abort_unless($contract->employee_id === $employee->id, 404);

        $contract->update(['status' => 'archived']);
        $audit->log('employee_contract.status_changed', ['actor' => $request->user(), 'subject' => $contract,
            'metadata' => ['status' => 'archived']]);

        return response()->json(['id' => $contract->id, 'status' => $contract->status]);
    }
}
