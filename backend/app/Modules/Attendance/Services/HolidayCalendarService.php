<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\HolidayCalendar;
use App\Modules\Attendance\Models\HolidayCalendarAssignment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Organization\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manage holiday calendars, their dates, and their company/branch assignments.
 * Scope targets are validated to belong to the tenant (RLS enforces it too; this
 * gives a clean 422). Changes are audited.
 */
class HolidayCalendarService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function createCalendar(array $data, mixed $actor = null): HolidayCalendar
    {
        $calendar = HolidayCalendar::query()->create($data);

        $this->audit->log('attendance.holiday_calendar_created', [
            'actor' => $actor, 'subject' => $calendar, 'metadata' => ['code' => $calendar->code],
        ]);

        return $calendar;
    }

    /** @param array<string, mixed> $data */
    public function updateCalendar(HolidayCalendar $calendar, array $data, mixed $actor = null): HolidayCalendar
    {
        $calendar->fill($data)->save();
        $this->audit->log('attendance.holiday_calendar_updated', ['actor' => $actor, 'subject' => $calendar]);

        return $calendar;
    }

    /** @param array<string, mixed> $data */
    public function addHoliday(HolidayCalendar $calendar, array $data, mixed $actor = null): Holiday
    {
        if (isset($data['end_date']) && $data['end_date'] !== null && $data['end_date'] < $data['date']) {
            throw ValidationException::withMessages(['end_date' => [__('attendance.holiday_end_before_start')]]);
        }

        $holiday = $calendar->holidays()->create($data);
        $this->audit->log('attendance.holiday_created', [
            'actor' => $actor, 'subject' => $holiday,
            'metadata' => ['calendar_id' => $calendar->id, 'date' => (string) $data['date']],
        ]);

        return $holiday;
    }

    public function deleteHoliday(Holiday $holiday, mixed $actor = null): void
    {
        $this->audit->log('attendance.holiday_deleted', [
            'actor' => $actor, 'subject' => $holiday, 'metadata' => ['calendar_id' => $holiday->holiday_calendar_id],
        ]);
        $holiday->delete();
    }

    /**
     * Assign a calendar to company or branch (branch validated within tenant).
     *
     * @param  array{scope_type:string, scope_id?:?string, effective_from:string, effective_until?:?string}  $data
     */
    public function assign(HolidayCalendar $calendar, array $data, mixed $actor = null): HolidayCalendarAssignment
    {
        $scopeType = $data['scope_type'];
        $scopeId = $data['scope_id'] ?? null;

        if ($scopeType === 'company') {
            $scopeId = null;
        } elseif ($scopeType === 'branch') {
            if ($scopeId === null || ! Branch::query()->whereKey($scopeId)->exists()) {
                throw ValidationException::withMessages(['scope_id' => [__('attendance.branch_invalid')]]);
            }
        } else {
            throw ValidationException::withMessages(['scope_type' => [__('attendance.holiday_scope_invalid')]]);
        }

        return DB::transaction(function () use ($calendar, $scopeType, $scopeId, $data, $actor) {
            $assignment = $calendar->assignments()->create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
            ]);

            $this->audit->log('attendance.holiday_assignment_created', [
                'actor' => $actor, 'subject' => $assignment,
                'metadata' => ['calendar_id' => $calendar->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
            ]);

            return $assignment;
        });
    }

    public function unassign(HolidayCalendarAssignment $assignment, mixed $actor = null): void
    {
        $this->audit->log('attendance.holiday_assignment_removed', [
            'actor' => $actor, 'subject' => $assignment,
            'metadata' => ['calendar_id' => $assignment->holiday_calendar_id],
        ]);
        $assignment->delete();
    }
}
