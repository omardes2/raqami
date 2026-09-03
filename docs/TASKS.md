# Tasks & Teams — Raqmi Dawam

**Status:** **Implemented in Sprint 6** (`feature/sprint-6-tasks-teams`, ADR-022).
A production-grade task & team-collaboration module built on the existing
Organization identities. It reuses `Team`, `TeamMembership`, `Department`,
`Branch`, and `Employee` — no team concept is duplicated. There is **no**
workflow/BPM engine, **no** time tracking, **no** Leave/Attendance/Payroll
coupling, and **no** notification transport (Sprint 8).

Governing principle (as elsewhere): the **server decides** every authorization,
scope, and derived result; the client never supplies authoritative visibility.

---

## 1. Identities — User ≠ Employee

- **Assignees** are `Employee` records (workforce identity).
- **Comments, mentions, watchers, activity actor, uploaders** are `User` records
  (account identity, for future notifications). An `Employee` may have **no**
  linked `User`; auto-watch skips such assignees (identities are never fabricated).

## 2. Projects (optional) → Tasks → Teams

- **Team** is an existing organizational grouping, never a project.
- **Project** is an optional, tenant-owned work container. A task may live inside
  a project **or** stand alone.
- **Task** is a concrete unit of work.

## 3. Stable organizational scope

Placement uses a single `(scope_type, scope_id)` pair (`company | branch |
department | team`), mirroring ADR-015 — never three competing columns. A
**standalone** task carries its own stable scope (unchanged by assignment churn);
a **project** task stores no scope and inherits the project's (a DB CHECK enforces
the project-XOR-standalone-scope exclusivity). `scope_id` is same-tenant
validated. Company ⇒ `scope_id` null; branch/department/team ⇒ required.

## 4. Central visibility (TaskVisibilityResolver)

One authority decides intra-tenant visibility for **every** surface — detail,
lists, My Tasks, board, comments, mentions, watchers, attachments, activity,
workload, reports. RLS guards the tenant boundary; the resolver guards
authorization inside it (out-of-scope ids → **scope-safe 404**). Layers:
company task authority → assignee → creator → project visibility (members_only
ignores org scope) → standalone scoped-grant coverage. Authorization is by real
RBAC grants + project-local ACL, never by role display name.

- **`scoped` project:** visible via covering RBAC scope, membership, owner, or a
  task assignment.
- **`members_only` project:** ordinary org scope never reveals it — only the
  owner, members, company authority, and (for their own task) direct assignees.
  Reports/workload are built on the resolver so hidden counts never leak.

## 5. Project-local authority × global RBAC

`project_memberships` is a bounded ACL (`manager | member`); the canonical owner
is `projects.owner_employee_id` (never a membership row). A project **manager**
membership may manage/assign tasks **inside that project only** — never company
task settings, other projects, or company reports. **Governance** (membership,
owner transfer, visibility/scope change, archive/unarchive) requires the owner
or `projects.manage` — a project-local manager membership can never perform it.

## 6. Tasks

Priority is a bounded enum (`low|normal|high|urgent`). Subtasks are **one level**
(a subtask cannot have subtasks; same project/scope; non-archived parent).
Optimistic `version` guards edits (stale → **409**). Creator-scoped idempotency
(`client_request_id` + a creation fingerprint): same key + same payload reuses
the row; same key + different payload → **409**.

## 7. Assignments

Multiple assignees; **at most one primary** (DB partial-unique index). A broad
(company/scoped) `tasks.assign` actor may assign employees within the task scope;
a project-local owner/manager without a broad grant may assign only existing
project participants (add them as a member first). New assignment to an inactive
employee is rejected; existing historical assignments are preserved.

## 8. Status catalog & semantics

`task_statuses` is a tenant-wide catalog. **`category` is the semantic truth**
(`backlog|todo|in_progress|blocked|done|cancelled`) — there is no `is_terminal`
column. `done` sets `completed_at`; `cancelled` closes without successful
completion (never overdue, never workload); leaving `done` clears `completed_at`.
Tenants customize name/code/color/order and may add multiple statuses per
category, but a status's category is **locked once referenced** by any task. The
immutable `bootstrap_key` gives idempotent bootstrap (`TaskStatusBootstrapService`
at onboarding; `tasks:bootstrap-statuses` backfill). Exactly one active default is
kept (DB partial-unique at-most-one; service at-least-one; the sole default cannot
be deactivated without a replacement). Statuses are deactivated, never
hard-deleted while referenced.

## 9. Due dates & overdue

`due_type` (`none|date|datetime`) with a DB CHECK on field shape. Date-only stores
`due_on` + IANA `due_timezone`; datetime stores `due_at` (UTC) + a display
timezone. **Overdue is server-authoritative:** a date-only task is overdue only
after the end of its local day; a datetime task when the instant passes; a
terminal or archived task is never overdue.

## 10. Kanban ordering

`board_rank` (bigint) applies **only** to top-level project tasks, within a
`project_id + status_id` column — sparse spacing, midpoint inserts, and a
**synchronous single-column renormalization** on gap exhaustion (no background
job, no floats). Standalone/global/My-Tasks views ignore `board_rank` and sort
deterministically with an `id` tie-break.

## 11. Collaboration

- **Checklist:** lightweight in-task ticks (no assignee/due/status).
- **Comments:** author = User; soft-delete only; optimistic edit/delete; creator-
  scoped idempotent create.
- **Mentions:** reference a User; persisted only for a same-tenant user who
  already has task visibility — a mention never grants visibility.
- **Watchers:** a User notification preference; never grants visibility.
  Auto-watch = creator + linked assignees only (commenters may watch explicitly).
- **Attachments:** private disk, tenant-prefixed keys, hidden `storage_key`,
  streamed/authorized downloads; a comment-scoped attachment must belong to the
  same task.

## 12. Activity vs audit

`task_activity_events` is an **append-only** (RLS SELECT/INSERT + mutation-reject
trigger) user-facing timeline; metadata carries only IDs / enum transitions /
safe labels — never comment bodies, file bytes, storage keys, or sensitive text.
Security-sensitive actions are additionally recorded by `AuditLogger` (separate
store).

## 13. Reports & workload

Derived, visibility-safe aggregates: tasks by status/priority, overdue, and a
transparent per-employee **workload** (active / high-urgent / overdue /
estimated-minutes / due-soon). Explicitly **not** a performance or disciplinary
score; no AI. Project progress = `done ÷ (done + open)` over top-level,
non-archived tasks (cancelled and subtasks excluded; empty project → null).

## 14. Permissions & default roles

`tasks.view_own`, `tasks.create`, `tasks.view`, `tasks.manage`, `tasks.assign`,
`tasks.comment`, `tasks.attach`, `tasks.reports.view`, `tasks.settings.manage`;
`projects.view`, `projects.create`, `projects.manage`. Status change and checklist
are assignee-inherent participation (not a permission). Defaults: Owner `*`; Admin
full; HR Manager `tasks.reports.view` only; Department Manager scoped
view/manage/assign + projects + reports; Team Leader scoped `tasks.view` +
`tasks.assign` **only with the matching team-scoped grant** (the team-lead
relation alone is never sufficient); Employee participation only (**no**
`tasks.create` by default); Accountant none.

## 15. Security, RLS, cross-tenant integrity

FORCE RLS (`tenant_isolation` + `platform_readonly`) on all **11** task tables;
`task_activity_events` is additionally append-only. Raw-SQL cross-tenant isolation
and platform-readonly write denial are tested. Explicit same-tenant validation
guards every supplied relation (project scope target, owner/assignee employee,
parent task, status, comment↔task, attachment↔comment↔task, mention user), beyond
FK + RLS.

## 16. Out of scope (Sprint 6)

Labels; task dependencies; Leave/Attendance integration; recurrence/RRULE; time
tracking/timers/timesheets; Gantt/roadmap/portfolio; generic workflow/SLA engine;
external guests/client portal; AI; notification delivery (domain events only —
Sprint 8); Payroll; performance scoring; automated reassignment; advanced
resource planning.
