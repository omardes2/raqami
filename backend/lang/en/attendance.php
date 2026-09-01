<?php

return [
    // Eligibility / sessions
    'not_eligible' => 'This employee is not eligible to record attendance.',
    'already_open' => 'This employee already has an open attendance; check out first.',
    'already_recorded_today' => 'This employee already has attendance recorded for this day.',
    'no_open' => 'There is no open attendance to check out.',
    'checkout_before_checkin' => 'Check-out cannot be before check-in.',
    'checkout_after_checkin' => 'Check-out must be after check-in.',

    // Scheduling
    'no_schedule' => 'No work schedule is assigned for this day.',
    'not_working_day' => 'Today is not a scheduled working day.',
    'early_not_allowed' => 'Early check-in is not allowed.',
    'too_early' => 'It is too early to check in.',
    'late_not_allowed' => 'Late check-in is not allowed.',

    // Location / GPS
    'location_required' => 'Location is required to check in.',
    'location_required_out' => 'Location is required to check out.',
    'gps_inaccurate' => 'The GPS reading is not accurate enough to check in.',
    'gps_inaccurate_out' => 'The GPS reading is not accurate enough to check out.',
    'outside_geofence' => 'You are outside the allowed check-in area.',

    // Corrections
    'corrections_disabled' => 'Attendance corrections are disabled for this company.',
    'employee_corrections_disabled' => 'Employee correction requests are disabled.',
    'correction_empty' => 'A correction must change the check-in and/or check-out time.',
    'correction_reviewed' => 'This correction has already been reviewed.',
    'correction_self' => 'You cannot review your own correction request.',

    // Schedule assignments
    'assignment_company_no_scope' => 'A company-scope assignment must not specify a scope_id.',
    'assignment_scope_required' => 'A scope_id is required for this scope type.',
    'assignment_scope_missing' => 'The scope target does not exist in this tenant.',

    // Identity / relations
    'not_linked' => 'Your account is not linked to an employee record.',
    'branch_invalid' => 'The selected branch does not exist in this tenant.',
    'date_range_too_large' => 'The date range is too large; narrow it to one year or less.',
];
