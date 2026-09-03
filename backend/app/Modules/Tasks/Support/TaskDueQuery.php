<?php

namespace App\Modules\Tasks\Support;

/**
 * The single SQL definition of "overdue" and "due soon" for aggregate/list
 * surfaces, kept consistent with the authoritative per-task Task::isOverdue().
 *
 * Date-only tasks are timezone-correct per row: the deadline instant is the local
 * midnight immediately AFTER due_on in the row's due_timezone
 * (`(due_on + 1 day) AT TIME ZONE due_timezone`), NOT the UTC session date. This
 * lets two tasks with the same due_on but different timezones hold different
 * overdue states at one instant. Terminal (done|cancelled) and archived tasks are
 * never overdue/due-soon.
 */
class TaskDueQuery
{
    /** Days-ahead window for "due soon". */
    public const DUE_SOON_DAYS = 7;

    /** Raw SQL boolean: the task is overdue right now. */
    public static function overdue(string $alias = 'tasks'): string
    {
        $deadline = self::deadlineInstant($alias);

        return "({$alias}.archived_at IS NULL"
            .' AND '.self::nonTerminal($alias)
            ." AND (({$alias}.due_type = 'datetime' AND {$alias}.due_at < now())"
            ." OR ({$alias}.due_type = 'date' AND {$deadline} <= now())))";
    }

    /** Raw SQL boolean: the task is due within DUE_SOON_DAYS and not yet overdue. */
    public static function dueSoon(string $alias = 'tasks'): string
    {
        $deadline = self::deadlineInstant($alias);
        $window = "now() + interval '".self::DUE_SOON_DAYS." days'";

        return "({$alias}.archived_at IS NULL"
            .' AND '.self::nonTerminal($alias)
            ." AND (({$alias}.due_type = 'datetime' AND {$alias}.due_at > now() AND {$alias}.due_at <= {$window})"
            ." OR ({$alias}.due_type = 'date' AND {$deadline} > now() AND {$deadline} <= {$window})))";
    }

    /** The per-row effective deadline instant for a date-only task (timestamptz). */
    private static function deadlineInstant(string $alias): string
    {
        return "(({$alias}.due_on + INTERVAL '1 day')::timestamp AT TIME ZONE {$alias}.due_timezone)";
    }

    /** The task's status category is non-terminal (excludes done + cancelled). */
    private static function nonTerminal(string $alias): string
    {
        return "EXISTS (SELECT 1 FROM task_statuses ts WHERE ts.id = {$alias}.status_id"
            ." AND ts.category NOT IN ('done', 'cancelled'))";
    }
}
