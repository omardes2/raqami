<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\PeriodType;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Models\LeavePolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolves (and lazily creates) the entitlement period that contains a date for
 * an (employee, leave_type). The basis — calendar year, employment anniversary,
 * or a custom tenant year — is policy/tenant driven; global SaaS never assumes
 * Jan 1. All windows are computed by exact date arithmetic (date-only bounds).
 */
class LeaveEntitlementPeriodService
{
    public function __construct(private readonly LeaveSettingsService $settings) {}

    /**
     * The period covering $date for (employee, leaveType), created if missing.
     */
    public function resolveOrCreate(
        Employee $employee,
        string $leaveTypeId,
        ?LeavePolicy $policy,
        CarbonInterface $date,
    ): LeaveEntitlementPeriod {
        $basis = $this->basisFor($policy);
        [$startsOn, $endsOn] = $this->windowFor($basis, $employee, CarbonImmutable::parse($date));

        return LeaveEntitlementPeriod::query()->firstOrCreate(
            [
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $leaveTypeId,
                'starts_on' => $startsOn->toDateString(),
            ],
            [
                'leave_policy_id' => $policy?->getKey(),
                'period_type' => $basis->value,
                'ends_on' => $endsOn->toDateString(),
                'status' => 'open',
            ],
        );
    }

    private function basisFor(?LeavePolicy $policy): PeriodType
    {
        if ($policy?->period_basis instanceof PeriodType) {
            return $policy->period_basis;
        }

        $default = $this->settings->current()->default_period_basis;

        return $default instanceof PeriodType ? $default : PeriodType::CalendarYear;
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable} [starts_on, ends_on] date-only
     */
    public function windowFor(PeriodType $basis, Employee $employee, CarbonImmutable $date): array
    {
        return match ($basis) {
            PeriodType::CalendarYear => [
                $date->startOfYear()->startOfDay(),
                $date->endOfYear()->startOfDay(),
            ],
            PeriodType::EmploymentAnniversary => $this->anniversaryWindow($employee, $date),
            PeriodType::CustomTenantYear => $this->customYearWindow($date),
        };
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function anniversaryWindow(Employee $employee, CarbonImmutable $date): array
    {
        $hire = $employee->hire_date ? CarbonImmutable::parse($employee->hire_date) : null;
        if ($hire === null) {
            // No hire date → fall back to a calendar year.
            return [$date->startOfYear()->startOfDay(), $date->endOfYear()->startOfDay()];
        }

        return $this->anchoredYear($date, (int) $hire->month, (int) $hire->day);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function customYearWindow(CarbonImmutable $date): array
    {
        $settings = $this->settings->current();

        return $this->anchoredYear($date, (int) $settings->leave_year_start_month, (int) $settings->leave_year_start_day);
    }

    /**
     * The year-long window anchored on (month, day) that contains $date.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function anchoredYear(CarbonImmutable $date, int $month, int $day): array
    {
        $anchorThisYear = CarbonImmutable::create($date->year, $month, $day)->startOfDay();

        $start = $anchorThisYear->greaterThan($date)
            ? $anchorThisYear->subYear()
            : $anchorThisYear;

        $end = $start->addYear()->subDay();

        return [$start, $end];
    }
}
