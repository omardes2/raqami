<?php

namespace App\Modules\Employees\Support;

/** Foundational, extensible enumerations for Sprint 1 (no payroll behavior). */
class EmployeeEnums
{
    public const EMPLOYMENT_STATUSES = [
        'active', 'onboarding', 'probation', 'suspended', 'on_leave', 'terminated', 'archived',
    ];

    public const EMPLOYMENT_TYPES = [
        'full_time', 'part_time', 'contract', 'temporary', 'internship', 'freelance',
    ];

    public const DOCUMENT_CATEGORIES = [
        'contract', 'id', 'certificate', 'cv', 'policy', 'other',
    ];

    public const CONTRACT_TYPES = [
        'permanent', 'fixed_term', 'contractor', 'internship',
    ];

    public const CONTRACT_STATUSES = [
        'draft', 'active', 'ended', 'terminated', 'archived',
    ];
}
