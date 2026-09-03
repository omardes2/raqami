<?php

namespace App\Modules\Tasks\Enums;

/**
 * Known user-facing activity event types for task_activity_events (D4). Stored as
 * a plain string column (no native DB enum) so future values are additive.
 */
enum TaskActivityType: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskStatusChanged = 'task.status_changed';
    case TaskPriorityChanged = 'task.priority_changed';
    case TaskAssigned = 'task.assigned';
    case TaskUnassigned = 'task.unassigned';
    case TaskDueChanged = 'task.due_changed';
    case TaskCompleted = 'task.completed';
    case TaskReopened = 'task.reopened';
    case TaskArchived = 'task.archived';
    case TaskUnarchived = 'task.unarchived';
    case CommentCreated = 'comment.created';
    case CommentEdited = 'comment.edited';
    case CommentDeleted = 'comment.deleted';
    case ChecklistCompleted = 'checklist.completed';
    case AttachmentAdded = 'attachment.added';
    case AttachmentDeleted = 'attachment.deleted';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
