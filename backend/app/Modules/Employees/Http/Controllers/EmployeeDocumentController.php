<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\EmployeeDocumentRequest;
use App\Modules\Employees\Http\Resources\EmployeeDocumentResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeDocument;
use App\Modules\Employees\Services\EmployeeDocumentService;
use App\Modules\Employees\Support\AuthorizesEmployeeScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDocumentController extends Controller
{
    use AuthorizesEmployeeScope;

    public function __construct(private readonly EmployeeDocumentService $documents) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        $docs = $employee->documents()->orderByDesc('created_at')->get();

        return response()->json(['data' => EmployeeDocumentResource::collection($docs)]);
    }

    public function store(EmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        $document = $this->documents->store($employee, $request->file('file'), $request->validated(), $request->user());

        return (new EmployeeDocumentResource($document))->response()->setStatusCode(201);
    }

    /** Authorized, streamed download — never a public storage URL. */
    public function download(Request $request, Employee $employee, EmployeeDocument $document)
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.view');
        abort_unless($document->employee_id === $employee->id, 404);

        return $this->documents->download($document, $request->user());
    }

    public function destroy(Request $request, Employee $employee, EmployeeDocument $document): JsonResponse
    {
        $this->authorizeEmployeeScope($request, $employee, 'employees.update');
        abort_unless($document->employee_id === $employee->id, 404);

        $this->documents->delete($document, $request->user());

        return response()->json(['id' => $document->id, 'removed' => true]);
    }
}
