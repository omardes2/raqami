<?php

namespace App\Modules\Tasks\Enums;

/**
 * The fixed semantic truth of a status (Correction E). `done` = successful
 * completion (sets completed_at); `cancelled` = closed but NOT successful
 * (completed_at stays null); the rest are non-terminal. Tenants may create many
 * statuses per category but never invent a category.
 */
enum TaskStatusCategory: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /** Whether entering this category represents successful completion. */
    public function isCompleted(): bool
    {
        return $this === self::Done;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
