<?php

return [
    // Authorization
    'self_payroll_forbidden' => 'You may not manage your own payroll data.',

    // Compensation
    'compensation_overlap' => 'This compensation range overlaps an existing one for the employee.',
    'effective_to_before_from' => 'The end date cannot be before the start date.',

    // Components
    'component_inactive' => 'This component is inactive and cannot be assigned.',
    'component_overlap' => 'This component range overlaps an existing one for the employee.',
    'fixed_requires_amount_currency' => 'A fixed component requires an amount and a currency.',
    'percent_requires_bps' => 'A percentage component requires a positive basis-point rate.',

    // Periods
    'period_must_start_month' => 'A payroll period must start on the first day of a month.',
    'period_must_be_full_month' => 'A payroll period must cover a full calendar month.',
    'period_exists' => 'A payroll period already exists for that month.',
    'period_closed' => 'This payroll period is closed.',

    // Runs
    'run_exists' => 'An active payroll run already exists for this period.',
    'run_not_cancellable' => 'This payroll run can no longer be cancelled.',

    // Calculation
    'run_not_calculable' => 'This payroll run cannot be calculated from its current state.',
    'run_calculation_in_progress' => 'A calculation for this run is already in progress.',
];
