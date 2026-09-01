<?php

namespace App\Modules\Attendance\Enums;

/**
 * Organizational level a schedule assignment targets. Resolution precedence,
 * most specific first: employee > team > department > branch > company.
 */
enum ScheduleScopeType: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Department = 'department';
    case Team = 'team';
    case Employee = 'employee';

    /**
     * Specificity rank — higher wins during resolution. Company (0) is the
     * broadest fallback; employee (4) is the most specific.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::Company => 0,
            self::Branch => 1,
            self::Department => 2,
            self::Team => 3,
            self::Employee => 4,
        };
    }

    /** Company scope has no scope_id; every other level requires one. */
    public function requiresScopeId(): bool
    {
        return $this !== self::Company;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
