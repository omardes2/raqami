<?php

return [
    // Policy / consumption-basis validation (D7)
    'nominal_minutes_required' => 'A nominal-calendar-day policy must define nominal day minutes greater than zero.',
    'count_days_requires_nominal' => 'Counting holidays or non-working days requires the nominal-calendar-day consumption basis.',
    'scope_target_invalid' => 'The selected scope target is invalid for this company.',

    // Request submission
    'not_eligible' => 'This employee is not eligible to request leave.',
    'no_policy' => 'No leave policy applies to this employee for the selected leave type and dates.',
    'no_coverage' => 'The selected dates do not include any leave to take.',
    'half_day_not_allowed' => 'Half-day leave is not allowed for this leave type or policy.',
    'half_day_single_day' => 'Half-day leave must be for a single day.',
    'half_day_requires_schedule' => 'Half-day leave requires scheduled working hours on that day.',
    'insufficient_balance' => 'There is not enough leave balance for this request.',
    'overlap' => 'This request overlaps another active leave request.',
    'min_request_minutes' => 'This request is shorter than the minimum allowed.',
    'max_request_minutes' => 'This request is longer than the maximum allowed.',
    'notice_too_short' => 'This request does not meet the minimum notice period.',
    'too_far_ahead' => 'This request is beyond the maximum advance booking window.',
    'attachment_required' => 'This leave type requires an attachment.',
    'invalid_date_range' => 'The end date must be on or after the start date.',

    // Lifecycle / concurrency
    'not_pending' => 'This request is no longer pending.',
    'stale' => 'This request has changed since it was loaded; please reload and try again.',
    'not_withdrawable' => 'This request can no longer be withdrawn.',
    'withdrawal_not_allowed' => 'Withdrawal is not allowed for this leave policy.',

    // Approval
    'self_approval_forbidden' => 'You cannot approve your own leave request.',
    'request_reviewed' => 'This approval step has already been decided.',
    'no_pending_step' => 'There is no pending approval step for you to act on.',
    'negative_override_forbidden' => 'Approving into a negative balance requires the negative-balance override permission.',

    // Cancellation
    'not_cancellable' => 'This leave cannot be cancelled in its current state.',
    'cancellation_not_allowed' => 'Cancellation requests are not allowed for this leave policy.',
    'cancellation_reason_required' => 'A reason is required to cancel approved leave.',
    'not_cancellation_pending' => 'This request does not have a pending cancellation.',

    // Balance adjustments
    'adjustment_reason_required' => 'A reason is required for a balance adjustment.',

    // Attachments
    'attachment_forbidden' => 'You are not allowed to access this attachment.',

    // Statuses (display)
    'status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        'cancellation_pending' => 'Cancellation pending',
        'cancelled' => 'Cancelled',
    ],
];
