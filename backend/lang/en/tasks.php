<?php

return [
    // Generic
    'stale' => 'This item was updated by someone else. Reload and try again.',
    'employee_invalid' => 'The selected employee is not valid for this company.',
    'scope_target_invalid' => 'The selected organizational scope is not valid.',

    // Projects
    'project_forbidden' => 'You are not allowed to manage this project.',
    'project_scope_forbidden' => 'You are not allowed to create a project in this scope.',
    'project_governance_forbidden' => 'Only the project owner or an authorized manager may change project governance.',
    'project_already_archived' => 'This project is already archived.',
    'project_not_archived' => 'This project is not archived.',
    'project_archived_readonly' => 'This project is archived and cannot be modified.',
    'member_is_owner' => 'The project owner is already a participant and cannot be added as a member.',

    // Tasks
    'task_forbidden' => 'You are not allowed to act on this task.',
    'task_create_forbidden' => 'You are not allowed to create a task in this scope.',
    'task_archived_readonly' => 'This task is archived and cannot be modified.',
    'task_scope_required' => 'A standalone task requires an organizational scope.',
    'task_scope_conflict' => 'A project task cannot declare its own scope.',
    'project_closed_for_tasks' => 'This project is archived or completed and cannot accept new tasks.',
    'idempotency_conflict' => 'A different request already used this idempotency key.',

    // Status
    'status_invalid' => 'The selected status is not valid.',
    'status_inactive' => 'An inactive status cannot be assigned to a task.',
    'status_category_locked' => 'The category of a status already in use cannot be changed.',
    'status_default_required' => 'A tenant must keep exactly one active default status.',
    'status_in_use' => 'A status referenced by tasks cannot be deleted; deactivate it instead.',

    // Subtasks
    'subtask_depth' => 'Subtasks are limited to one level.',
    'subtask_parent_archived' => 'A subtask cannot be added to an archived task.',
    'subtask_scope_mismatch' => 'A subtask must share its parent project or scope.',
    'self_parent' => 'A task cannot be its own parent.',

    // Assignment
    'assignee_inactive' => 'An inactive employee cannot be newly assigned.',
    'assignee_scope_forbidden' => 'This employee is not a valid assignee for the task scope.',
    'assign_forbidden' => 'You are not allowed to assign this task.',
    'assignee_not_project_participant' => 'Add the employee as a project member before assigning them.',

    // Comments
    'comment_forbidden' => 'You are not allowed to comment on this task.',
    'comment_edit_forbidden' => 'You are not allowed to edit this comment.',
    'comment_deleted' => 'This comment has been deleted.',

    // Mentions
    'mention_invalid' => 'One or more mentioned users cannot be mentioned on this task.',

    // Attachments
    'attachment_comment_mismatch' => 'The attachment does not belong to this task or comment.',

    // Kanban
    'board_rank_project_only' => 'Manual ordering applies only to top-level project tasks.',

    // Settings
    'settings_forbidden' => 'You are not allowed to manage task settings.',

    // UI notices
    'subtasks_incomplete_notice' => 'This task has incomplete subtasks.',
];
