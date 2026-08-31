# Tasks & Team Management — Raqmi Dawam

**Status:** Design (planning phase). Covers task creation/assignment/tracking,
team management, and AI-assisted task generation.

---

## 1. Goals

- Let managers and teams create, assign, and track work.
- Provide clear visibility scoped by role and team.
- Integrate with notifications, reports, and AI (task generation, workload
  analysis).

## 1a. V1 Scope (ADR-016)

Tasks V1 includes: **tasks, subtasks, Kanban workflow, priorities, due dates,
comments, attachments, assignees, and teams.** **Advanced dependencies and Gantt
charts are deferred** to a later release.

## 2. Concepts

### 2.1 Teams
A **team** groups employees (within or across departments) with an optional
**team lead**. Team membership drives task visibility and assignment defaults.

### 2.2 Tasks
A **task** has: title, description, assignee (employee), optional team, status,
priority, due date, and provenance (`ai_generated` flag). Tasks support
**comments** and **attachments**, and can have **subtasks** (via a
`parent_task_id` self-reference).

**Status:** `todo → in_progress → blocked → done` (or `cancelled`).
**Priority:** `low | medium | high | urgent`.

### 2.3 Kanban Workflow
Tasks are organized on a **Kanban board** of columns (e.g., To Do / In Progress /
Blocked / Done), configurable per team/board with optional WIP limits. A task's
`board_column_id` and `position` place it on the board; moving a card updates
both. See `DATABASE.md` (`board_columns`, `tasks`, `task_attachments`).

### 2.4 Subtasks
A task may have **subtasks** for breaking work down. Subtasks are tasks with a
`parent_task_id`; they carry their own status/assignee and roll up to the parent
for progress visibility.

### 2.5 Assignment & Visibility
- Employees see their **own** tasks (`task.view.own`).
- Managers/leads see their **team's** tasks (`task.view` scoped to team).
- Company Admin/HR see company tasks per permissions.
- Assignment respects tenant boundaries; cross-tenant assignment is impossible.

## 3. Workflows

```
Create task ─► assign (employee/team) ─► track status ─► complete
     │                                    │
     └─ AI generate ───────────────┘      └─ notifications on assign/status/due
```

- **Notifications:** on assignment, status change, and approaching/overdue due
  dates.
- **Reminders:** scheduled jobs notify assignees of due/overdue tasks.
- **Audit:** creation, reassignment, and deletion of tasks are recorded.

## 4. AI-Assisted Tasks (see `AI-FEATURES.md`)

- **AI task generation:** from a goal, meeting note, or project description, the
  assistant proposes a structured task list (title, priority, suggested
  assignee, due date). Proposals are **suggestions** — a human confirms before
  they are created. Generated tasks are flagged `ai_generated`.
- **AI workload analysis:** highlights overloaded/underloaded team members and
  suggests rebalancing, respecting permissions and tenant isolation.

## 5. Data Touchpoints

See `DATABASE.md`: `teams`, `team_member`, `board_columns`, `tasks` (with
`parent_task_id`, `board_column_id`), `task_comments`, `task_attachments`.

## 6. Permissions

See `PERMISSIONS.md` (Tasks module): `task.view`, `task.view.own`,
`task.create`, `task.update`, `task.assign`, `task.delete`, `task.ai.generate`.

## 7. Security & Isolation

- All task data is tenant-scoped; visibility further narrowed by role/team.
- AI task features only read data the requesting user may access.

## 8. Testing (when implemented)

Cover: assignment/visibility rules, status transitions, due-date reminders, AI
proposal → human-confirm flow, and tenant isolation.

## 9. Decision Status

**Decided (ADR-016):** subtasks and Kanban boards **are** in V1 (with priorities,
due dates, comments, attachments, assignees, teams). **Advanced dependencies and
Gantt charts are deferred.** No open task questions block their sprint.
