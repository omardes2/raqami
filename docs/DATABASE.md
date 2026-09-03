# Database Design (Conceptual) — Raqmi Dawam

**Status:** Conceptual model only. **No migrations are to be created yet.**
This document describes entities, relationships, and conventions to guide future
schema work. Types are indicative (PostgreSQL-oriented).

---

## 1. Conventions

- **Engine:** PostgreSQL (see `ARCHITECTURE.md`).
- **Tenancy (ADR-002):** shared database, shared schema. **Every tenant-owned
  table has a `tenant_id`** (FK → `tenants.id`), indexed, and included in
  uniqueness/index keys. Isolation is enforced by a Laravel tenant context +
  global query scopes + PostgreSQL Row-Level Security (RLS) + automated
  cross-tenant isolation tests. Cross-tenant access is a **critical security
  vulnerability**.
- **Primary keys:** `id` — **ULID** for application entities (ADR-008), unless
  there is a strong technical reason not to (documented per exception). ULIDs are
  time-ordered and sortable, avoid enumeration, and ease future scaling. Storage
  representation (26-char string vs 16-byte binary) is confirmed at Sprint 0
  kickoff.
- **Timestamps:** `created_at`, `updated_at` on all tables; `deleted_at` for
  soft deletes where appropriate.
- **Money:** store as integer minor units (e.g., cents) + `currency` (ISO 4217),
  never floats.
- **Enums:** represented as constrained string/enum columns documented per table.
- **Audit:** critical tables emit domain events consumed by the audit log.
- **Localization:** user-facing catalog data (e.g., leave type names) supports
  translations via translation keys or a translations table; **no hard-coded UI
  text in the DB**.

> **Central (landlord) vs. tenant tables:** `tenants`, `plans`, `subscriptions`,
> `invoices`, `payments`, and platform-level `users`/roles for Super Admin live
> in the **central** context. Company-scoped tables carry `tenant_id`.

---

## 2. Core Domains & Entities

### 2.1 Tenancy & SaaS

**tenants** (a company as a tenant)
- `id`, `name`, `slug`/`subdomain` (unique), `status`
  (`trialing|active|past_due|suspended|cancelled`), `locale_default`
  (`ar|en`), `timezone`, `country`, `created_at`, `updated_at`, `deleted_at`.

**plans**
- `id`, `code` (unique), `name`, `description`, `price_amount`, `currency`,
  `billing_cycle` (`monthly|yearly`), `employee_limit`, `feature_flags` (JSONB),
  `is_active`.

**subscriptions**
- `id`, `tenant_id`, `plan_id`, `status`
  (`trialing|active|past_due|cancelled|expired`), `trial_ends_at`,
  `current_period_start`, `current_period_end`, `cancel_at`, `canceled_at`,
  `payment_method` (`card|bank_transfer|manual`), timestamps.

**invoices**
- `id`, `tenant_id`, `subscription_id`, `number` (unique), `status`
  (`draft|open|paid|void|uncollectible`), `amount_due`, `amount_paid`,
  `currency`, `due_at`, `issued_at`, `paid_at`, line items (JSONB or child
  table).

**payments**
- `id`, `tenant_id`, `invoice_id`, `method` (`card|bank_transfer|manual`),
  `provider` (nullable), `provider_reference`, `amount`, `currency`, `status`
  (`pending|succeeded|failed|refunded`), `proof_url` (for manual/bank),
  `recorded_by` (user), `paid_at`, timestamps.

### 2.2 Identity & Access

**users**
- `id`, `tenant_id` (nullable for platform/Super Admin users),
  `name`, `email` (unique per tenant), `password_hash`, `locale` (`ar|en`),
  `status` (`active|invited|disabled`), `mfa_enabled`, `last_login_at`,
  timestamps, `deleted_at`.

**roles**
- `id`, `tenant_id` (nullable for system/platform roles), `name`, `slug`,
  `is_system`, `description`, timestamps.

**permissions**
- `id`, `key` (e.g., `attendance.view`, `payroll.run`), `module`,
  `description`. (Global catalog; see `PERMISSIONS.md`.)

**role_permission** (pivot)
- `role_id`, `permission_id`.

**user_role** (pivot, with scope — ADR-015)
- `user_id`, `role_id`, `tenant_id`, `scope_type`
  (`company|branch|department|team`), `scope_id` (nullable for company scope).
  A role is granted **within a scope**, so an authorized manager only reaches
  users/resources inside their assigned branch/department/team.

> Optionally an `employee` may be linked to a `user` (employees who log in).

### 2.3 Organization

**branches**
- `id`, `tenant_id`, `name`, `code`, `timezone`, `address`, `latitude`,
  `longitude`, `is_active`, timestamps.

**departments**
- `id`, `tenant_id`, `branch_id` (nullable), `parent_id` (self-FK for hierarchy),
  `name`, `code`, timestamps.

**employees**
- `id`, `tenant_id`, `user_id` (nullable), `branch_id`, `department_id`,
  `employee_number` (unique per tenant), `first_name`, `last_name`,
  `national_id` (**sensitive**), `email`, `phone`, `job_title`, `hire_date`,
  `employment_type` (`full_time|part_time|contract`), `status`
  (`active|on_leave|suspended|terminated`), `manager_id` (self-FK),
  `bank_account` (**sensitive**, encrypted), timestamps, `deleted_at`.

**teams**
- `id`, `tenant_id`, `name`, `lead_employee_id`, timestamps.

**team_member** (pivot)
- `team_id`, `employee_id`, `role_in_team`.

### 2.4 Attendance (see `ATTENDANCE.md`)

**work_schedules** (configurable working days & hours)
- `id`, `tenant_id`, `name`, `scope` (`company|branch|department|employee`),
  `scope_id`, `timezone`, `week_definition` (JSONB: working days & hours),
  `grace_in_minutes`, `grace_out_minutes`, `overtime_policy` (JSONB),
  `is_active`, timestamps.

**shifts**
- `id`, `tenant_id`, `work_schedule_id` (nullable), `name`, `start_time`,
  `end_time`, `crosses_midnight` (bool), `break_minutes`, timestamps.

**shift_assignments**
- `id`, `tenant_id`, `employee_id`, `shift_id`, `date` (or range),
  `rotation` (JSONB, optional), timestamps.

**geofences**
- `id`, `tenant_id`, `branch_id` (nullable), `name`, `center_lat`,
  `center_lng`, `radius_meters` (or `polygon` GEOGRAPHY), `enforcement`
  (`enforce|warn|off`), timestamps.

**attendance_records** (high volume — partitioning is a **future** strategy only,
not built initially; see ADR-009)
- `id`, `tenant_id`, `employee_id`, `date`, `shift_id` (nullable),
  `check_in_at`, `check_out_at`, `check_in_lat`, `check_in_lng`,
  `check_out_lat`, `check_out_lng`, `check_in_within_geofence` (bool),
  `check_out_within_geofence` (bool), `source` (`web|mobile|kiosk|manual`),
  `late_minutes`, `early_leave_minutes`, `overtime_minutes`,
  `worked_minutes`, `status` (`present|late|absent|leave|holiday`),
  `notes`, `adjusted_by` (nullable), timestamps.

### 2.5 Leave Management

**leave_types**
- `id`, `tenant_id`, `name` (translatable), `code`, `is_paid`,
  `accrual_policy` (JSONB), `max_balance`, `requires_approval`, timestamps.

**leave_balances**
- `id`, `tenant_id`, `employee_id`, `leave_type_id`, `balance`, `period`,
  timestamps.

**leave_requests**
- `id`, `tenant_id`, `employee_id`, `leave_type_id`, `start_date`, `end_date`,
  `days`, `reason`, `status` (`pending|approved|rejected|cancelled`),
  `approver_id`, `decided_at`, `decision_note`, timestamps.

> **SUPERSEDED by Sprint 5 (see `LEAVE.md` / ADR-021).** The conceptual model
> above (mutable `leave_balances.balance`, floating `days`) is **not** what was
> built. Sprint 5 uses **integer minutes** as the canonical unit, an **immutable
> ledger** (`leave_balance_transactions`) as the source of truth with a maintained
> projection (`leave_balances`: granted/accrued/carried/adjusted/used/reserved/
> expired/available minutes + `version`), and separates balance **consumption**
> from attendance **coverage**. Real Sprint 5 tenant tables (all `tenant_id` +
> FORCE RLS): `leave_types`, `leave_policies`, `leave_policy_assignments`,
> `leave_entitlement_periods`, `leave_balances`, `leave_balance_transactions`
> (append-only), `leave_requests` (statuses `pending|approved|rejected|withdrawn|
> cancellation_pending|cancelled`, `version`), `leave_request_days` (per-work_date
> snapshot: scheduled/coverage/consumption minutes, coverage_intervals,
> consumption_basis, holiday/schedule snapshots), `leave_request_approvals`
> (snapshotted steps), `leave_request_attachments` (private), `leave_settings`.
> **No `attendance_records` schema change** — leave↔attendance is many-to-many,
> resolved via `leave_request_days` + `LeaveResolver`.

### 2.6 Tasks & Teams (see `TASKS.md`) — Tasks V1 (ADR-016)

**board_columns** (Kanban workflow, per tenant/team/board)
- `id`, `tenant_id`, `team_id` (nullable), `name` (translatable), `position`,
  `wip_limit` (nullable), timestamps.

**tasks**
- `id`, `tenant_id`, `parent_task_id` (self-FK, nullable → **subtasks**),
  `board_column_id` (nullable → Kanban position), `position` (within column),
  `title`, `description`, `created_by`, `assignee_id` (employee),
  `team_id` (nullable), `status`
  (`todo|in_progress|blocked|done|cancelled`), `priority`
  (`low|medium|high|urgent`), `due_at`, `completed_at`, `ai_generated` (bool),
  timestamps.

**task_comments**
- `id`, `tenant_id`, `task_id`, `author_id`, `body`, timestamps.

**task_attachments**
- `id`, `tenant_id`, `task_id`, `uploaded_by`, `file_url` (private storage),
  `filename`, `content_type`, `size_bytes`, timestamps.

> Advanced dependencies and Gantt charts are **deferred** (ADR-016).

### 2.7 Payroll (see `PAYROLL.md`) — generic core, modular country rules (ADR-014)

**country_rule_sets** (pluggable country payroll providers; no country rules
hard-coded into the core)
- `id`, `country_code` (ISO 3166), `provider_key`, `version`, `is_active`,
  `parameters` (JSONB: statutory rates, rounding, contribution rules),
  `effective_from`, `effective_to`, timestamps.
  *(A tenant/company references the rule set applicable to its country; the
  Payroll Core reads rules from the provider rather than embedding them.)*

**payroll_runs**
- `id`, `tenant_id`, `period_start`, `period_end`, `status`
  (`draft|processing|review|approved|paid|cancelled`), `run_by`, `approved_by`,
  `approved_at`, `totals` (JSONB), timestamps.

**payroll_items** (per employee per run — **sensitive**)
- `id`, `tenant_id`, `payroll_run_id`, `employee_id`, `base_amount`,
  `allowances` (JSONB), `deductions` (JSONB), `overtime_amount`,
  `gross_amount`, `net_amount`, `currency`, `breakdown` (JSONB), timestamps.

**payslips**
- `id`, `tenant_id`, `payroll_item_id`, `employee_id`, `document_url`,
  `issued_at`, `locale`, timestamps.

### 2.8 Notifications

**notifications**
- `id`, `tenant_id`, `user_id`, `type`, `channel` (`in_app|email|sms|push`),
  `payload` (JSONB), `read_at`, `sent_at`, timestamps.

### 2.9 Audit Logs (see `SECURITY.md`) — append-only

**audit_logs** (high volume — partitioning is a **future** strategy only, not
built initially; see ADR-009)
- `id`, `tenant_id` (nullable for platform actions), `actor_user_id`,
  `actor_type` (`user|system|super_admin`), `action` (e.g.,
  `payroll.approved`), `entity_type`, `entity_id`, `before` (JSONB, redacted),
  `after` (JSONB, redacted), `ip`, `user_agent`, `context` (JSONB),
  `created_at`. **No updates/deletes** (append-only).

### 2.10 AI (see `AI-FEATURES.md`)

**ai_conversations**
- `id`, `tenant_id`, `user_id`, `title`, `context_scope` (JSONB), timestamps.

**ai_messages**
- `id`, `tenant_id`, `conversation_id`, `role` (`user|assistant|system`),
  `content`, `tokens` (nullable), `created_at`.

**ai_jobs** (async insights/reports/task-gen)
- `id`, `tenant_id`, `type` (`report|insight|task_gen|workload`), `status`,
  `input` (JSONB), `result` (JSONB), `requested_by`, timestamps.

### 2.11 Compliance — GDPR-ready seams (ADR-013)

> Lightweight tables that make the platform GDPR-ready without premature
> complexity. See `SECURITY.md`.

**consents** (consent tracking where applicable)
- `id`, `tenant_id` (nullable for platform-level), `subject_type`
  (`user|employee`), `subject_id`, `consent_type`, `granted` (bool),
  `granted_at`, `revoked_at`, `source`, timestamps.

**data_export_requests** (account/data export)
- `id`, `tenant_id`, `requested_by`, `subject_type`, `subject_id`, `status`
  (`pending|processing|ready|delivered|failed`), `export_url` (private,
  signed), `requested_at`, `completed_at`, timestamps.

**data_deletion_requests** (data deletion workflows)
- `id`, `tenant_id`, `requested_by`, `subject_type`, `subject_id`, `status`
  (`pending|approved|processing|completed|rejected`), `reason`,
  `scheduled_for`, `completed_at`, `approved_by`, timestamps.
  *(Deletion respects legal retention holds and destructive-change approval —
  `CLAUDE.md` rule 2.)*

**retention_policies** (data retention policies)
- `id`, `tenant_id` (nullable for platform default), `data_class`,
  `retention_days`, `action` (`delete|anonymize`), `is_active`, timestamps.

---

## 3. Key Relationships (conceptual ER)

```
tenants 1───* subscriptions *───1 plans
tenants 1───* invoices 1───* payments
tenants 1───* users *───* roles *───* permissions
tenants 1───* branches 1───* departments 1───* employees
employees *───* teams
tenants 1───* work_schedules 1───* shifts 1───* shift_assignments *───1 employees
tenants 1───* geofences  (─ optional ─ branches)
employees 1───* attendance_records
employees 1───* leave_requests *───1 leave_types
employees 1───* leave_balances *───1 leave_types
tenants 1───* tasks *───1 employees (assignee)
tasks 1───* tasks (parent_task_id → subtasks)
tasks *───1 board_columns  (Kanban)
tasks 1───* task_comments · tasks 1───* task_attachments
country_rule_sets ──used by── payroll_runs (generic core, modular country rules)
payroll_runs 1───* payroll_items 1───1 payslips *───1 employees
tenants 1───* notifications *───1 users
tenants 1───* audit_logs
tenants 1───* ai_conversations 1───* ai_messages
tenants 1───* consents · data_export_requests · data_deletion_requests · retention_policies
```

## 4. Indexing & Performance (guidelines)

- Composite indexes lead with `tenant_id` (e.g.,
  `(tenant_id, employee_id, date)` on `attendance_records`).
- Uniqueness is **per tenant** (e.g., `unique(tenant_id, employee_number)`).
- **No partitioning in the initial system (ADR-009).** Indexes are chosen so
  that partitioning of high-volume, time-series tables (`attendance_records`,
  `audit_logs`, `notifications`) can be introduced **later** without a data-model
  rewrite.
- Keep analytics off the transactional path (read replicas / OLAP are **future**
  strategies).

## 5. Data Protection

- **Sensitive columns** (`national_id`, `bank_account`, payroll amounts) are
  permission-gated at the app layer and encrypted at rest where applicable.
- Soft-delete tenant data; hard deletes and destructive migrations require
  approval (`CLAUDE.md` rule 2) with a tested rollback.
- Audit logs are append-only and never carry raw secrets (redact in
  `before`/`after`).

## 6. Settled & Still Open

**Settled (see `DECISIONS.md`):** PK type = **ULID** (ADR-008); tenancy =
**shared DB + `tenant_id` + RLS** (ADR-002); **no partitioning initially**
(ADR-009).

**Still open (do not block Sprint 0):** ULID storage representation (26-char
string vs 16-byte binary) — confirmed at kickoff; exact RLS policy SQL; first
country payroll rule set(s) for `country_rule_sets`.

> Reminder: **Do not create migrations yet.** This is a conceptual model only.

---

## 7. Sprint 1 — Organization & Employees (implemented)

Sprint 1 implemented the following tenant-owned tables (all with indexed
`tenant_id`, ULID `char(26)` keys, per-tenant unique codes, and RLS
`ENABLE`+`FORCE` with the `tenant_isolation` + `platform_readonly` policies):

- **branches** — name, code (unique/tenant), country_code, city, address_line,
  per-branch timezone, phone, email, is_headquarters, status.
- **job_titles** — title, code, level, status. **No salary/compensation.**
- **departments** — hierarchical (`parent_department_id`, cycle-prevented in the
  service), `branch_id` (nullable = company-wide), `manager_employee_id`.
- **employees** — the HR record, **separate from `users`** (nullable `user_id`).
  Identity, org placement (branch/department/job_title/direct_manager, self-FK),
  employment (status/type/hire/probation/termination), contact, profile, notes;
  `status` + soft deletes for archive.
- **teams** + **team_memberships** — teams distinct from departments; employees
  belong to 0..N teams.
- **employee_emergency_contacts**, **employee_documents** (metadata only; files
  in private storage via `storage_key`), **employee_contracts** (no
  compensation), **employee_history_events** (append-oriented HR timeline,
  distinct from `audit_logs`).

Cross-entity and self-referential FKs (employees.department_id, employees
.direct_manager_employee_id, departments.parent_department_id, departments
.manager_employee_id, teams.team_lead_employee_id) are added in a deferred FK
migration to avoid PostgreSQL create-order issues. No partitioning (ADR-009).
Employee number default format is `EMP-000123` (per-tenant unique; configurable
format is a future concern).

---

## 8. Sprint 2 — SaaS Billing & Subscriptions (implemented)

Sprint 2 refines the §1 note that placed subscriptions/invoices/payments in the
"central" context: those are **tenant-linked** (indexed `tenant_id`, RLS
ENABLE+FORCE), so a tenant's billing records are isolated; the Super Admin portal
reads them cross-tenant only via the audited platform read-only context.

**Platform-global** (no `tenant_id`, no RLS): `plans`, `plan_features`,
`coupons`, `bank_accounts`.

**Tenant-linked** (`tenant_id` + RLS): `subscriptions` (one per tenant),
`subscription_changes`, `subscription_events`, `billing_profiles`, `invoices`,
`invoice_items`, `payments`, `bank_transfer_submissions`, `coupon_redemptions`,
`billing_counters`.

**Infrastructure** (global, not tenant-owned): `payment_webhook_events`,
`idempotency_records`.

Conventions: ULID `char(26)` keys, integer minor-unit money, ISO-4217 currency,
per-tenant unique `invoice_number` (`INV-YYYY-######` via an atomic counter),
`payments.idempotency_key` unique. No partitioning (ADR-009).

### Sprint 2 — commercial hardening (schema deltas)

- **Removed** `billing_counters` (per-tenant). **Added** platform-global
  `invoice_number_sequences` (year unique; no tenant_id/RLS) for globally-unique
  invoice numbers; `invoices.invoice_number` now has a **global** unique index.
- **Added** `plans.is_default_trial` (partial unique index enforces at most one
  active default trial plan) and `subscription_changes.invoice_id` (links a
  pending upgrade/reactivation to the invoice whose payment applies it).
- Tenant-linked billing tables are now nine (billing_counters removed):
  billing_profiles, subscriptions, subscription_changes, subscription_events,
  invoices, invoice_items, payments, bank_transfer_submissions,
  coupon_redemptions — all with `tenant_id` + FORCE RLS.

---

## 9. Sprint 3 — Attendance Core (implemented)

Sprint 3 implemented eight tenant-owned tables (all with indexed `tenant_id`,
ULID `char(26)` keys, and RLS `ENABLE`+`FORCE` with the `tenant_isolation` +
`platform_readonly` policies). Timestamps are **UTC**; `work_date`/`timezone`
carry the schedule-timezone context.

- **attendance_settings** — one row per tenant (`unique(tenant_id)`): default
  timezone/grace, geofence/GPS requirements, min accuracy, early/late windows,
  overtime tracking, correction toggles, allow-unscheduled-work.
- **work_schedules** — reusable schedule header (name, code unique/tenant,
  timezone, grace/break/overtime defaults, status).
- **work_schedule_days** — per-weekday hours (0=Sun..6=Sat); off days;
  `end_time <= start_time` denotes an overnight window.
- **work_schedule_assignments** — schedule ↔ organizational scope
  (company|branch|department|team|employee) with effective dates + priority;
  resolved by `ScheduleResolver` (precedence most-specific-first).
- **attendance_locations** — approved geofences (center lat/long as decimals,
  radius, optional required accuracy, optional branch link).
- **attendance_records** — computed **daily state** (one per employee per
  `work_date`, `unique(tenant_id, employee_id, work_date)`): schedule-boundary
  snapshot, check-in/out (UTC), worked/late/early-leave/overtime/break/grace
  minutes, status, source, geo summary, is_manual, corrected_at. A **partial
  unique index** enforces at most one open (not-checked-out) record per employee.
- **attendance_events** — append-only **raw punch log**: event type, source,
  occurred_at, exactly what the client sent (lat/long/accuracy) and what the
  server decided (matched location, distance, inside_geofence), metadata,
  actor, and `client_request_id` (partial unique index → idempotent retries).
- **attendance_corrections** — controlled correction workflow (requested
  in/out, reason, status pending/approved/rejected, reviewer, before/after
  snapshots); no self-approval (service-enforced).

No partitioning yet (ADR-009); `attendance_records`/`attendance_events` remain
the high-volume candidates. Concurrency is protected by a per-employee advisory
xact lock + row locks around check-in/out.

### Sprint 4 — Attendance Advanced (new tenant tables)

All carry `tenant_id` + FORCE RLS (`tenant_isolation` + `platform_readonly`),
proven by raw-SQL cross-tenant tests.

- **attendance_sessions** — individual check-in/out sessions; several closed per
  `work_date` (split shifts), **at most one open** per employee (partial unique
  index `… WHERE check_out_at IS NULL`). Server computes every minute field; the
  daily `attendance_records` row is re-aggregated from these.
- **work_schedule_segments** — split-shift expected windows under
  `work_schedule_days` (`unique(work_schedule_day_id, sequence)`).
- **holiday_calendars / holidays / holiday_calendar_assignments** — holiday
  calendars, their (single or multi-day) holidays, and company/branch
  assignments with effective dates; resolved branch > company.
- **attendance_exceptions** — authorized remote/field/off-day/alternate
  exceptions (effective dates, mode, alternate schedule/location, status).
- **overtime_approvals** — one per `attendance_record` (`unique(tenant_id,
  attendance_record_id)`); raw `calculated_minutes` kept separate from
  `approved_minutes`; status pending/approved/rejected.
- **attendance_anomalies** — neutral rule-based findings with a `dedupe_key`
  (`unique(tenant_id, dedupe_key)` → idempotent detection), severity, status,
  and jsonb metadata.

`attendance_corrections` gained a nullable `attendance_session_id` (FK, nullOnDelete)
so corrections target the authoritative session; legacy rows keep it null.

`attendance_records` gained `version` (optimistic concurrency), `attendance_mode`,
`is_materialized` / `materialized_at`, and `holiday_id`; the Sprint 3 one-open-
record index moved to `attendance_sessions`. `work_schedules` gained
`cycle_length_days` + `anchor_date` (rotation). `attendance_settings` gained the
Sprint 4 policy columns. All Sprint 4 migrations are **additive** with idempotent
backfills (a default segment per working day; a session per existing record) —
no Sprint 3 data is dropped or transformed destructively.

## Sprint 6 — Tasks & Teams (ADR-022)

Eleven additive tenant-owned tables (all ULID PKs, `tenant_id`, FORCE RLS):
`projects`, `project_memberships`, `task_statuses`, `tasks`, `task_assignees`,
`task_checklist_items`, `task_comments`, `task_comment_mentions`, `task_watchers`,
`task_attachments`, `task_activity_events` (append-only). Bounded semantic values
are `string` + CHECK (no native ENUM). Key invariants are DB-enforced: project
scope (`company` ⇒ null / else required), task scope-source exclusivity (project
XOR standalone scope), due-field shape per `due_type`, `board_rank` only on project
tasks, one active default status per tenant (partial unique), one primary assignee
per task (partial unique), creator-scoped idempotency on tasks/comments (partial
unique), and one system status per `bootstrap_key`. No Organization/Attendance/
Leave schema was modified; there is no `attendance_records` change.
