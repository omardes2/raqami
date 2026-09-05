<?php

namespace App\Modules\Notifications\Services;

/**
 * Outcome of a NotificationService::notify() call. Invalid-recipient cases are a
 * normal, non-throwing outcome (Skipped); Duplicate reflects the DB dedupe.
 */
enum NotificationResult: string
{
    case Created = 'created';
    case Duplicate = 'duplicate';
    case Skipped = 'skipped';
}
