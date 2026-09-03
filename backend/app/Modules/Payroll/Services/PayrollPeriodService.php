<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll periods (Correction A). V1 regular periods are FULL CALENDAR
 * MONTHS only; one per tenant/month. The tenant payroll timezone is snapshotted
 * at creation so later settings changes never rewrite a historical period.
 */
class PayrollPeriodService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PayrollSettingsService $settings,
    ) {}

    /** @return Collection<int, PayrollPeriod> */
    public function list(): Collection
    {
        return PayrollPeriod::query()->orderByDesc('period_start')->get();
    }

    /**
     * @param  array{period_start:string, period_end?:string, label?:string}  $data
     */
    public function create(User $actor, array $data): PayrollPeriod
    {
        $start = Carbon::parse($data['period_start'])->startOfDay();
        if (! $start->equalTo($start->copy()->startOfMonth())) {
            throw ValidationException::withMessages(['period_start' => [__('payroll.period_must_start_month')]]);
        }
        $end = $start->copy()->endOfMonth()->startOfDay();
        if (! empty($data['period_end']) && ! Carbon::parse($data['period_end'])->startOfDay()->equalTo($end)) {
            throw ValidationException::withMessages(['period_end' => [__('payroll.period_must_be_full_month')]]);
        }

        $timezone = $this->settings->getOrCreate()->payroll_timezone;
        $startDate = $start->toDateString();
        $label = $data['label'] ?? $start->format('Y-m');

        return DB::transaction(function () use ($actor, $startDate, $end, $timezone, $label) {
            if (PayrollPeriod::query()->where('period_start', $startDate)->exists()) {
                throw ValidationException::withMessages(['period_start' => [__('payroll.period_exists')]]);
            }

            $period = PayrollPeriod::query()->create([
                'label' => $label,
                'period_start' => $startDate,
                'period_end' => $end->toDateString(),
                'timezone' => $timezone,
                'status' => PayrollPeriodStatus::Open,
                'created_by_user_id' => (string) $actor->getKey(),
            ]);

            $this->audit->log('payroll.period_created', [
                'actor' => $actor, 'subject' => $period,
                'metadata' => ['period_start' => $startDate, 'period_end' => $end->toDateString()],
            ]);

            return $period->fresh();
        });
    }
}
