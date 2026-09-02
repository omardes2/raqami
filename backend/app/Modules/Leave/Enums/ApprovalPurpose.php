<?php

namespace App\Modules\Leave\Enums;

/** Whether an approval step approves the original request or its cancellation. */
enum ApprovalPurpose: string
{
    case Approval = 'approval';
    case Cancellation = 'cancellation';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
