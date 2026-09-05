<?php

namespace App\Modules\Notifications\Services;

/**
 * Sprint 8B Phase 3 hardening — the ONLY sanctioned way for a domain producer to
 * build a notification. Each named constructor hardcodes a stable type + stable
 * translation key and maps exactly the safe, locale-neutral params that event
 * needs (names, type labels, dates, result flags). No method accepts an
 * arbitrary array, a model, or request data; sensitive fields (salary, ids,
 * bank, medical, private reasons, snapshots) are structurally impossible to
 * pass. NotificationPayload re-validates as defence in depth.
 */
final class NotificationPayloadFactory
{
    // --- Leave --------------------------------------------------------------

    /**
     * To a definitive (named) approver: a leave request awaits their decision.
     * Only the requesting employee's name is included — never the leave type,
     * which for categories like "Sick Leave" is a health signal that must not
     * persist in a notification row (the approver sees full detail, authorized,
     * in the Leave module itself).
     */
    public static function leaveRequestSubmitted(
        string $leaveRequestId,
        string $approverUserId,
        string $employeeName,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'leave.request.submitted',
            key: 'notifications.leave.request_submitted',
            params: ['employee_name' => $employeeName],
            subjectType: 'leave_request',
            subjectId: $leaveRequestId,
            dedupeKey: "leave.request.submitted:{$leaveRequestId}:{$approverUserId}",
        );
    }

    /** To the requester: their leave request was approved (no type/reason text). */
    public static function leaveRequestApproved(
        string $leaveRequestId,
        int $version,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'leave.request.approved',
            key: 'notifications.leave.request_approved',
            params: [],
            subjectType: 'leave_request',
            subjectId: $leaveRequestId,
            dedupeKey: "leave.request.approved:{$leaveRequestId}:v{$version}",
        );
    }

    /** To the requester: their leave request was rejected (no type/reason text — may be private). */
    public static function leaveRequestRejected(
        string $leaveRequestId,
        int $version,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'leave.request.rejected',
            key: 'notifications.leave.request_rejected',
            params: [],
            subjectType: 'leave_request',
            subjectId: $leaveRequestId,
            dedupeKey: "leave.request.rejected:{$leaveRequestId}:v{$version}",
        );
    }

    // --- Tasks --------------------------------------------------------------

    /**
     * To the (new) assignee: a task was assigned to them. The activity-event id
     * discriminates transitions so A → B → A produces distinct notifications; no
     * task title/content is included (subject_id carries the task for an
     * authorized deep-link).
     */
    public static function taskAssigned(
        string $taskId,
        string $activityEventId,
        string $assigneeUserId,
        bool $isReassignment,
    ): NotificationPayload {
        $type = $isReassignment ? 'task.reassigned' : 'task.assigned';
        $key = $isReassignment ? 'notifications.task.reassigned' : 'notifications.task.assigned';

        return new NotificationPayload(
            type: $type,
            key: $key,
            params: [],
            subjectType: 'task',
            subjectId: $taskId,
            dedupeKey: "{$type}:{$activityEventId}:{$assigneeUserId}",
        );
    }

    /** To the assignee: a visible, non-terminal task is due soon. One per due-date. */
    public static function taskDueSoon(
        string $taskId,
        string $dueOn,
        string $assigneeUserId,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'task.due_soon',
            key: 'notifications.task.due_soon',
            params: ['due_on' => $dueOn],
            subjectType: 'task',
            subjectId: $taskId,
            dedupeKey: "task.due_soon:{$taskId}:{$dueOn}:{$assigneeUserId}",
        );
    }

    /** To the assignee: a visible, non-terminal task is overdue. One per due-date. */
    public static function taskOverdue(
        string $taskId,
        string $dueOn,
        string $assigneeUserId,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'task.overdue',
            key: 'notifications.task.overdue',
            params: ['due_on' => $dueOn],
            subjectType: 'task',
            subjectId: $taskId,
            dedupeKey: "task.overdue:{$taskId}:{$dueOn}:{$assigneeUserId}",
        );
    }

    // --- Attendance ---------------------------------------------------------

    /** To the correction requester: their correction was reviewed (approved/rejected only). */
    public static function attendanceCorrectionReviewed(
        string $correctionId,
        string $result,
    ): NotificationPayload {
        $result = $result === 'approved' ? 'approved' : 'rejected';

        return new NotificationPayload(
            type: 'attendance.correction.reviewed',
            key: 'notifications.attendance.correction_reviewed',
            params: ['result' => $result],
            subjectType: 'attendance_correction',
            subjectId: $correctionId,
            dedupeKey: "attendance.correction.reviewed:{$correctionId}",
        );
    }

    // --- Payroll ------------------------------------------------------------

    /** To the employee's user: a finalized payslip is available. Never any money. */
    public static function payrollPayslipAvailable(
        string $payrollEntryId,
        string $period,
    ): NotificationPayload {
        return new NotificationPayload(
            type: 'payroll.payslip_available',
            key: 'notifications.payroll.payslip_available',
            params: ['period' => $period],
            subjectType: 'payroll_entry',
            subjectId: $payrollEntryId,
            dedupeKey: "payroll.payslip_available:{$payrollEntryId}",
        );
    }
}
