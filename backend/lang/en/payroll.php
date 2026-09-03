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

    // Adjustments (Phase 2B)
    'adjustment_run_locked' => 'Adjustments can only be changed before the run is approved or finalized.',
    'adjustment_employee_not_in_period' => 'This employee does not belong to the payroll period.',
    'adjustment_invalid_source' => 'The source payroll entry must be the same employee’s finalized entry from an earlier period.',

    // Approval (Phase 2B)
    'run_not_approvable' => 'Only a calculated payroll run can be approved.',
    'approval_not_required' => 'Approval is not required: four-eyes control is disabled, so a calculated run can be finalized directly.',
    'four_eyes_approver' => 'Four-eyes control: the approver must be different from the person who requested the calculation.',
    'run_has_no_entries' => 'This payroll run has no entries to approve or finalize.',
    'run_has_unresolved_entries' => 'This payroll run has entries that are not cleanly calculated; recalculate it first.',
    'run_cohort_stale' => 'The set of employees changed since calculation; recalculate the run first.',
    'run_inputs_stale' => 'Some payroll inputs changed since calculation; recalculate the run first.',

    // Finalization (Phase 2B)
    'run_not_finalizable' => 'This payroll run cannot be finalized from its current state.',
    'four_eyes_finalizer' => 'Four-eyes control: the finalizer must be different from the approver.',
    'run_already_finalized' => 'This payroll run is already finalized.',
    'calculation_version_mismatch' => 'This run was calculated with a different engine version; recalculate it first.',
    'stored_snapshot_tampered' => 'The stored calculation snapshot failed its integrity check; recalculate the run.',
    'result_revalidation_failed' => 'Revalidation of the payroll result failed; recalculate the run.',
    'persisted_lines_tampered' => 'The stored payroll lines failed their integrity check; recalculate the run.',
    'negative_net_requires_override' => 'This run has a negative net; finalizing it requires the negative-net override permission and a reason.',
];
