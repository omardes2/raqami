<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\LinkUserRequest;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeUserLinkService;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use Illuminate\Http\Request;

class EmployeeUserLinkController extends Controller
{
    use AuthorizesEmployeeScope;

    public function store(LinkUserRequest $request, Employee $employee, EmployeeUserLinkService $service): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.link_user');
        $employee = $service->link($employee, $request->string('user_id'), $request->user());

        return new EmployeeResource($employee);
    }

    public function destroy(Request $request, Employee $employee, EmployeeUserLinkService $service): EmployeeResource
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.link_user');
        $employee = $service->unlink($employee, $request->user());

        return new EmployeeResource($employee);
    }
}
