<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\ScheduleScopeType;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Models\WorkScheduleAssignment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create and maintain work schedules, their per-weekday hours, and their
 * assignments to organizational scopes. All scope targets are validated to
 * belong to the current tenant (RLS already enforces this at the DB level; this
 * gives a clean 422 instead of a silent no-match). Changes are audited.
 */
class WorkScheduleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a schedule plus its weekday rows in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $days
     */
    public function create(array $attributes, array $days, mixed $actor = null): WorkSchedule
    {
        return DB::transaction(function () use ($attributes, $days, $actor) {
            $schedule = WorkSchedule::query()->create($attributes);
            $this->syncDays($schedule, $days);

            $this->audit->log('attendance.schedule_created', [
                'actor' => $actor,
                'subject' => $schedule,
                'metadata' => ['code' => $schedule->code],
            ]);

            return $schedule->load('days');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>|null  $days
     */
    public function update(WorkSchedule $schedule, array $attributes, ?array $days = null, mixed $actor = null): WorkSchedule
    {
        return DB::transaction(function () use ($schedule, $attributes, $days, $actor) {
            $schedule->fill($attributes)->save();

            if ($days !== null) {
                $this->syncDays($schedule, $days);
            }

            $this->audit->log('attendance.schedule_updated', [
                'actor' => $actor,
                'subject' => $schedule,
            ]);

            return $schedule->load('days');
        });
    }

    /**
     * Replace the schedule's weekday rows. Each entry must carry weekday (0-6);
     * a working day requires start_time + end_time.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    public function syncDays(WorkSchedule $schedule, array $days): void
    {
        $schedule->days()->delete();

        foreach ($days as $day) {
            $schedule->days()->create([
                'weekday' => $day['weekday'],
                'is_working_day' => $day['is_working_day'] ?? true,
                'start_time' => $day['start_time'] ?? null,
                'end_time' => $day['end_time'] ?? null,
                'break_minutes' => $day['break_minutes'] ?? null,
                'grace_minutes' => $day['grace_minutes'] ?? null,
            ]);
        }
    }

    /**
     * Assign a schedule to an organizational scope after validating the target.
     *
     * @param  array{scope_type:string, scope_id?:?string, effective_from:string,
     *     effective_until?:?string, priority?:int}  $data
     */
    public function assign(WorkSchedule $schedule, array $data, mixed $actor = null): WorkScheduleAssignment
    {
        $scopeType = ScheduleScopeType::from($data['scope_type']);
        $scopeId = $data['scope_id'] ?? null;

        $this->validateScope($scopeType, $scopeId);

        return DB::transaction(function () use ($schedule, $scopeType, $scopeId, $data, $actor) {
            $assignment = $schedule->assignments()->create([
                'scope_type' => $scopeType->value,
                'scope_id' => $scopeType->requiresScopeId() ? $scopeId : null,
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'priority' => $data['priority'] ?? 0,
            ]);

            $this->audit->log('attendance.schedule_assigned', [
                'actor' => $actor,
                'subject' => $assignment,
                'metadata' => [
                    'work_schedule_id' => $schedule->id,
                    'scope_type' => $scopeType->value,
                    'scope_id' => $assignment->scope_id,
                ],
            ]);

            return $assignment;
        });
    }

    public function unassign(WorkScheduleAssignment $assignment, mixed $actor = null): void
    {
        DB::transaction(function () use ($assignment, $actor) {
            $this->audit->log('attendance.schedule_unassigned', [
                'actor' => $actor,
                'subject' => $assignment,
                'metadata' => [
                    'work_schedule_id' => $assignment->work_schedule_id,
                    'scope_type' => $assignment->scope_type->value,
                    'scope_id' => $assignment->scope_id,
                ],
            ]);

            $assignment->delete();
        });
    }

    /**
     * Ensure the scope target exists within the tenant (or is a company scope).
     */
    private function validateScope(ScheduleScopeType $type, ?string $scopeId): void
    {
        if (! $type->requiresScopeId()) {
            if ($scopeId !== null) {
                throw ValidationException::withMessages([
                    'scope_id' => ['A company-scope assignment must not specify a scope_id.'],
                ]);
            }

            return;
        }

        if ($scopeId === null) {
            throw ValidationException::withMessages([
                'scope_id' => ['A scope_id is required for this scope type.'],
            ]);
        }

        $exists = match ($type) {
            ScheduleScopeType::Branch => Branch::query()->whereKey($scopeId)->exists(),
            ScheduleScopeType::Department => Department::query()->whereKey($scopeId)->exists(),
            ScheduleScopeType::Team => Team::query()->whereKey($scopeId)->exists(),
            ScheduleScopeType::Employee => Employee::query()->whereKey($scopeId)->exists(),
            ScheduleScopeType::Company => true,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'scope_id' => ['The scope target does not exist in this tenant.'],
            ]);
        }
    }
}
