<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Leave\Http\Requests\LeaveAdjustmentRequest;
use App\Modules\Leave\Http\Resources\LeaveBalanceResource;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Services\LeaveAdjustmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Management view of leave balances (scoped) and manual adjustments. Never a
 * direct balance write — adjustments post a signed immutable ledger row. Pushing
 * a balance negative requires leave.negative_override (scope-checked).
 */
class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveAdjustmentService $adjustments,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeaveBalance::query()
            ->whereHas('employee', fn ($q) => $this->scope->applyScope($q, $request->user(), 'leave.balances.view'))
            ->orderByDesc('updated_at');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        return LeaveBalanceResource::collection(
            $query->paginate(min((int) $request->query('per_page', 50), 200))
        )->response();
    }

    public function adjust(LeaveAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $employee = Employee::query()->find($data['employee_id']);
        abort_if($employee === null || ! $this->scope->canAccess($request->user(), $employee, 'leave.balances.adjust'), 404);

        // A negative-pushing adjustment additionally requires the override permission.
        $wantsOverride = (bool) ($data['allow_negative_override'] ?? false);
        if ($wantsOverride && ! $this->scope->canAccess($request->user(), $employee, 'leave.negative_override')) {
            abort(403, __('leave.negative_override_forbidden'));
        }

        $transaction = $this->adjustments->adjust(
            $employee,
            (string) $data['leave_type_id'],
            (int) $data['minutes'],
            (string) $data['reason'],
            $request->user(),
            isset($data['effective_date']) ? CarbonImmutable::parse($data['effective_date']) : null,
            $wantsOverride,
        );

        return response()->json(['data' => [
            'id' => $transaction->id,
            'transaction_type' => $transaction->transaction_type?->value,
            'minutes' => (int) $transaction->minutes,
            'effective_date' => $transaction->effective_date?->toDateString(),
        ]], 201);
    }
}
