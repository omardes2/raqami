<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\EmergencyContactRequest;
use App\Modules\Employees\Http\Resources\EmergencyContactResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeEmergencyContact;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Emergency contacts are sensitive — gated by employees.view_sensitive.
class EmergencyContactController extends Controller
{
    use AuthorizesEmployeeScope;

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        $contacts = $employee->emergencyContacts()->orderByDesc('is_primary')->get();

        return response()->json(['data' => EmergencyContactResource::collection($contacts)]);
    }

    public function store(EmergencyContactRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        $contact = $employee->emergencyContacts()->create($request->validated());

        return (new EmergencyContactResource($contact))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Employee $employee, string $contact): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        EmployeeEmergencyContact::query()->where('employee_id', $employee->id)->whereKey($contact)->delete();

        return response()->json(['id' => $contact, 'removed' => true]);
    }
}
