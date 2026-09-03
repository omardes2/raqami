<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Models\EmployeeCompensationComponent;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Money\CurrencyMetadata;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effective-dated recurring components assigned to an employee. Value shape is
 * validated against the catalog component's calculation_mode: `fixed` needs a
 * minor-unit amount + currency; `percent_of_base` needs integer basis points and
 * no currency. Inactive catalog components cannot be newly assigned. Non-overlap
 * per (tenant, employee, component) via advisory lock + service check + trigger.
 */
class EmployeeCompensationComponentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    /** @return Collection<int, EmployeeCompensationComponent> */
    public function list(string $employeeId): Collection
    {
        $this->assertEmployeeInTenant($employeeId);

        return EmployeeCompensationComponent::query()
            ->where('employee_id', $employeeId)
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @param  array{payroll_component_id:string, fixed_amount_minor?:?int, rate_bps?:?int, currency?:?string, effective_from:string, effective_to?:?string}  $data
     */
    public function assign(User $actor, string $employeeId, array $data): EmployeeCompensationComponent
    {
        $this->assertEmployeeInTenant($employeeId);
        $this->authz->assertNotSelfManagement($actor, $employeeId);

        $component = PayrollComponent::query()->findOrFail($data['payroll_component_id']);
        if (! $component->active) {
            throw ValidationException::withMessages(['payroll_component_id' => [__('payroll.component_inactive')]]);
        }

        [$fixed, $bps, $currency] = $this->resolveShape($component, $data);
        $from = Carbon::parse($data['effective_from'])->toDateString();
        $to = ! empty($data['effective_to']) ? Carbon::parse($data['effective_to'])->toDateString() : null;

        return DB::transaction(function () use ($actor, $employeeId, $component, $fixed, $bps, $currency, $from, $to) {
            PayrollLock::forComponent((string) $this->context->tenantId(), $employeeId, (string) $component->getKey());
            $this->assertNoOverlap($employeeId, (string) $component->getKey(), $from, $to, null);

            $row = EmployeeCompensationComponent::query()->create([
                'employee_id' => $employeeId,
                'payroll_component_id' => $component->getKey(),
                'fixed_amount_minor' => $fixed,
                'rate_bps' => $bps,
                'currency' => $currency,
                'effective_from' => $from,
                'effective_to' => $to,
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            $this->audit->log('payroll.component_assigned', [
                'actor' => $actor, 'subject' => $row,
                'metadata' => ['employee_id' => $employeeId, 'payroll_component_id' => $component->getKey(), 'effective_from' => $from, 'effective_to' => $to],
            ]);

            return $row->fresh();
        });
    }

    public function end(User $actor, EmployeeCompensationComponent $assignment, string $effectiveTo): EmployeeCompensationComponent
    {
        $this->authz->assertNotSelfManagement($actor, (string) $assignment->employee_id);
        $to = Carbon::parse($effectiveTo)->toDateString();

        return DB::transaction(function () use ($actor, $assignment, $to) {
            PayrollLock::forComponent((string) $this->context->tenantId(), (string) $assignment->employee_id, (string) $assignment->payroll_component_id);
            $row = EmployeeCompensationComponent::query()->lockForUpdate()->findOrFail($assignment->getKey());

            if (Carbon::parse($to)->lessThan(Carbon::parse($row->effective_from->toDateString()))) {
                throw ValidationException::withMessages(['effective_to' => [__('payroll.effective_to_before_from')]]);
            }
            $this->assertNoOverlap((string) $row->employee_id, (string) $row->payroll_component_id, $row->effective_from->toDateString(), $to, $row->getKey());

            $row->effective_to = $to;
            $row->version = (int) $row->version + 1;
            $row->save();

            $this->audit->log('payroll.component_assignment_ended', [
                'actor' => $actor, 'subject' => $row,
                'metadata' => ['employee_id' => $row->employee_id, 'effective_to' => $to],
            ]);

            return $row->fresh();
        });
    }

    /** @return array{0:?int,1:?int,2:?string} [fixed_amount_minor, rate_bps, currency] */
    private function resolveShape(PayrollComponent $component, array $data): array
    {
        if ($component->calculation_mode === PayrollComponentMode::Fixed) {
            if (! isset($data['fixed_amount_minor']) || $data['fixed_amount_minor'] === null || empty($data['currency'])) {
                throw ValidationException::withMessages(['fixed_amount_minor' => [__('payroll.fixed_requires_amount_currency')]]);
            }

            return [(int) $data['fixed_amount_minor'], null, CurrencyMetadata::normalize($data['currency'])];
        }

        // percent_of_base
        if (! isset($data['rate_bps']) || (int) $data['rate_bps'] <= 0) {
            throw ValidationException::withMessages(['rate_bps' => [__('payroll.percent_requires_bps')]]);
        }

        return [null, (int) $data['rate_bps'], null];
    }

    private function assertEmployeeInTenant(string $employeeId): void
    {
        abort_unless(Employee::query()->whereKey($employeeId)->exists(), 404);
    }

    private function assertNoOverlap(string $employeeId, string $componentId, string $from, ?string $to, ?string $exceptId): void
    {
        $infinity = '9999-12-31';
        $newTo = $to ?? $infinity;

        $overlaps = EmployeeCompensationComponent::query()
            ->where('employee_id', $employeeId)
            ->where('payroll_component_id', $componentId)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('? <= COALESCE(effective_to, ?::date)', [$from, $infinity])
            ->whereRaw('effective_from <= ?::date', [$newTo])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => [__('payroll.component_overlap')]]);
        }
    }
}
