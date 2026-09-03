<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Money\CurrencyMetadata;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effective-dated monthly base salary. A salary change inserts a NEW effective-
 * dated row; a historical row is never rewritten. Non-overlap per (tenant,
 * employee) is guaranteed by an advisory lock + a service overlap check inside
 * the transaction (the DB trigger is the final backstop). Self-payroll is
 * blocked unless the tenant enables it.
 */
class EmployeeCompensationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    /** @return Collection<int, EmployeeCompensation> */
    public function history(string $employeeId): Collection
    {
        $this->assertEmployeeInTenant($employeeId);

        return EmployeeCompensation::query()
            ->where('employee_id', $employeeId)
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @param  array{currency:string, base_amount_minor:int, overtime_rate_minor_per_hour?:?int, effective_from:string, effective_to?:?string}  $data
     */
    public function create(User $actor, string $employeeId, array $data): EmployeeCompensation
    {
        $this->assertEmployeeInTenant($employeeId);
        $this->authz->assertNotSelfManagement($actor, $employeeId);

        $currency = CurrencyMetadata::normalize($data['currency']);
        $from = Carbon::parse($data['effective_from'])->toDateString();
        $to = ! empty($data['effective_to']) ? Carbon::parse($data['effective_to'])->toDateString() : null;

        return DB::transaction(function () use ($actor, $employeeId, $data, $currency, $from, $to) {
            PayrollLock::forCompensation((string) $this->context->tenantId(), $employeeId);
            $this->assertNoOverlap($employeeId, $from, $to, null);

            $row = EmployeeCompensation::query()->create([
                'employee_id' => $employeeId,
                'currency' => $currency,
                'base_amount_minor' => (int) $data['base_amount_minor'],
                'overtime_rate_minor_per_hour' => isset($data['overtime_rate_minor_per_hour']) && $data['overtime_rate_minor_per_hour'] !== null
                    ? (int) $data['overtime_rate_minor_per_hour'] : null,
                'effective_from' => $from,
                'effective_to' => $to,
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            // Audit records identity + dates + currency only — never the amount.
            $this->audit->log('payroll.compensation_created', [
                'actor' => $actor, 'subject' => $row,
                'metadata' => ['employee_id' => $employeeId, 'currency' => $currency, 'effective_from' => $from, 'effective_to' => $to],
            ]);

            return $row->fresh();
        });
    }

    /** End (or re-date the end of) an effective compensation row. */
    public function end(User $actor, EmployeeCompensation $compensation, string $effectiveTo): EmployeeCompensation
    {
        $this->authz->assertNotSelfManagement($actor, (string) $compensation->employee_id);
        $to = Carbon::parse($effectiveTo)->toDateString();

        return DB::transaction(function () use ($actor, $compensation, $to) {
            PayrollLock::forCompensation((string) $this->context->tenantId(), (string) $compensation->employee_id);
            $row = EmployeeCompensation::query()->lockForUpdate()->findOrFail($compensation->getKey());

            if (Carbon::parse($to)->lessThan(Carbon::parse($row->effective_from->toDateString()))) {
                throw ValidationException::withMessages(['effective_to' => [__('payroll.effective_to_before_from')]]);
            }
            $this->assertNoOverlap((string) $row->employee_id, $row->effective_from->toDateString(), $to, $row->getKey());

            $row->effective_to = $to;
            $row->version = (int) $row->version + 1;
            $row->save();

            $this->audit->log('payroll.compensation_ended', [
                'actor' => $actor, 'subject' => $row,
                'metadata' => ['employee_id' => $row->employee_id, 'effective_to' => $to],
            ]);

            return $row->fresh();
        });
    }

    private function assertEmployeeInTenant(string $employeeId): void
    {
        // RLS scopes Employee to the current tenant; an out-of-scope id is invisible.
        abort_unless(Employee::query()->whereKey($employeeId)->exists(), 404);
    }

    /** Reject an effective range overlapping an existing row for the employee. */
    private function assertNoOverlap(string $employeeId, string $from, ?string $to, ?string $exceptId): void
    {
        $infinity = '9999-12-31';
        $newTo = $to ?? $infinity;

        $overlaps = EmployeeCompensation::query()
            ->where('employee_id', $employeeId)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('? <= COALESCE(effective_to, ?::date)', [$from, $infinity])
            ->whereRaw('effective_from <= ?::date', [$newTo])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => [__('payroll.compensation_overlap')]]);
        }
    }
}
