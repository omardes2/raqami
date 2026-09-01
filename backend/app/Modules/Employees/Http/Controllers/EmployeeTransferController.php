<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\EmployeeTransferRequest;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeTransferService;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;

class EmployeeTransferController extends Controller
{
    use AuthorizesEmployeeScope;

    public function store(EmployeeTransferRequest $request, Employee $employee, EmployeeTransferService $service): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.transfer');
        $employee = $service->apply($employee, $request->validated(), $request->user());

        return new EmployeeResource($employee->load(['branch', 'department', 'jobTitle', 'manager', 'teams']));
    }
}
