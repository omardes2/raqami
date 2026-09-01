<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Resources\EmployeeHistoryResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeHistoryController extends Controller
{
    use AuthorizesEmployeeScope;

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');

        $events = $employee->historyEvents()->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => EmployeeHistoryResource::collection($events)]);
    }
}
