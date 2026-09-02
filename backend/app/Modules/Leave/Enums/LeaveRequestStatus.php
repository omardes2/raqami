<?php

namespace App\Modules\Leave\Enums;

/**
 * Leave request lifecycle. There is NO server-side draft (D5): a request begins
 * at `pending` on submit. `cancellation_pending` keeps the leave ACTIVE for
 * attendance until a final cancellation is approved (D3).
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';                       // submitted, awaiting approval
    case Approved = 'approved';                     // final approval
    case Rejected = 'rejected';                     // terminal
    case Withdrawn = 'withdrawn';                   // employee withdrew a pending request (terminal)
    case CancellationPending = 'cancellation_pending'; // approved leave with a cancellation request open
    case Cancelled = 'cancelled';                   // terminal

    /** Approved leave that Attendance/LeaveResolver must treat as ACTIVE coverage. */
    public function isActiveLeave(): bool
    {
        return $this === self::Approved || $this === self::CancellationPending;
    }

    /** No further transitions possible. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Withdrawn, self::Cancelled], true);
    }

    /** Statuses that hold a balance reservation (availability already reduced). */
    public function holdsReservation(): bool
    {
        return $this === self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
