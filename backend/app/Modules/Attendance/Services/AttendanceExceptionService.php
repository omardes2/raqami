<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceMode;
use App\Modules\Attendance\Enums\ExceptionType;
use App\Modules\Attendance\Models\AttendanceException;
use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages authorized attendance exceptions (remote / field / off-day work /
 * alternate location / alternate schedule). An employee can NEVER self-declare
 * these; an HR/manager actor creates them and the row IS the authorization. All
 * scope targets are validated to belong to the tenant (RLS enforces it too; this
 * yields a clean 422). Every change is audited.
 */
class AttendanceExceptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{type:string, effective_from:string, effective_until?:?string,
     *     attendance_mode?:?string, alternate_schedule_id?:?string,
     *     alternate_location_id?:?string, reason:string}  $data
     */
    public function create(Employee $employee, array $data, Model $actor): AttendanceException
    {
        $type = ExceptionType::from($data['type']);
        $this->validateWindow($data);
        $this->validateTargets($data);

        return DB::transaction(function () use ($employee, $type, $data, $actor) {
            $exception = AttendanceException::query()->create([
                'employee_id' => $employee->getKey(),
                'type' => $type->value,
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'attendance_mode' => $this->modeFor($type, $data),
                'alternate_schedule_id' => $data['alternate_schedule_id'] ?? null,
                'alternate_location_id' => $data['alternate_location_id'] ?? null,
                'reason' => $data['reason'],
                'status' => 'active',
                'approved_by_user_id' => (string) $actor->getKey(),
                'created_by_user_id' => (string) $actor->getKey(),
            ]);

            $this->audit->log('attendance.exception_created', [
                'actor' => $actor,
                'subject' => $exception,
                'metadata' => [
                    'employee_id' => (string) $employee->getKey(),
                    'type' => $type->value,
                    'effective_from' => (string) $data['effective_from'],
                ],
            ]);

            return $exception;
        });
    }

    /** Revoke an active exception (soft state change — history is preserved). */
    public function revoke(AttendanceException $exception, Model $actor): AttendanceException
    {
        if ($exception->status !== 'active') {
            throw ValidationException::withMessages(['status' => [__('attendance.exception_reviewed')]]);
        }

        return DB::transaction(function () use ($exception, $actor) {
            $exception->fill(['status' => 'revoked'])->save();

            $this->audit->log('attendance.exception_revoked', [
                'actor' => $actor,
                'subject' => $exception,
                'metadata' => ['employee_id' => (string) $exception->employee_id],
            ]);

            return $exception;
        });
    }

    /** @param array<string, mixed> $data */
    private function validateWindow(array $data): void
    {
        $until = $data['effective_until'] ?? null;
        if ($until !== null && $until < $data['effective_from']) {
            throw ValidationException::withMessages(['effective_until' => [__('attendance.exception_end_before_start')]]);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateTargets(array $data): void
    {
        $locationId = $data['alternate_location_id'] ?? null;
        if ($locationId !== null && ! AttendanceLocation::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['alternate_location_id' => [__('attendance.exception_alternate_location_invalid')]]);
        }

        $scheduleId = $data['alternate_schedule_id'] ?? null;
        if ($scheduleId !== null && ! WorkSchedule::query()->whereKey($scheduleId)->exists()) {
            throw ValidationException::withMessages(['alternate_schedule_id' => [__('attendance.exception_alternate_schedule_invalid')]]);
        }
    }

    /**
     * Derive the attendance_mode: remote/field carry their own mode; others use an
     * explicit mode when supplied, else none (onsite geofence still applies).
     *
     * @param  array<string, mixed>  $data
     */
    private function modeFor(ExceptionType $type, array $data): ?string
    {
        return match ($type) {
            ExceptionType::Remote => AttendanceMode::Remote->value,
            ExceptionType::Field => AttendanceMode::Field->value,
            default => isset($data['attendance_mode']) ? AttendanceMode::from($data['attendance_mode'])->value : null,
        };
    }
}
