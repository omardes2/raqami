# Database Design (Conceptual) — Raqmi Dawam

**Status:** Conceptual model only. **No migrations are to be created yet.**
This document describes entities, relationships, and conventions to guide future
schema work. Types are indicative (PostgreSQL-oriented).

---

## 1. Conventions

- **Engine:** PostgreSQL (see `ARCHITECTURE.md`).
- **Tenancy:** shared database, shared schema. **Every tenant-owned table has a
  `tenant_id`** (FK → `tenants.id`), indexed, and included in uniqueness/index
  keys. Row-Level Security (RLS) enforces isolation as defense-in-depth.
- **Primary keys:** `id` — prefer **UUID** (or ULID) for tenant-scoped entities
  to avoid enumeration and ease future sharding.
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

**user_role** (pivot)
- `user_id`, `role_id`, `tenant_id`.

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

**attendance_records** (high volume — candidate for partitioning by month)
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

### 2.6 Tasks & Teams (see `TASKS.md`)

**tasks**
- `id`, `tenant_id`, `title`, `description`, `created_by`, `assignee_id`
  (employee), `team_id` (nullable), `status`
  (`todo|in_progress|blocked|done|cancelled`), `priority`
  (`low|medium|high|urgent`), `due_at`, `completed_at`, `ai_generated` (bool),
  timestamps.

**task_comments**
- `id`, `tenant_id`, `task_id`, `author_id`, `body`, timestamps.

### 2.7 Payroll (see `PAYROLL.md`)

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

**audit_logs** (high volume — candidate for partitioning)
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
payroll_runs 1───* payroll_items 1───1 payslips *───1 employees
tenants 1───* notifications *───1 users
tenants 1───* audit_logs
tenants 1───* ai_conversations 1───* ai_messages
```

## 4. Indexing & Performance (guidelines)

- Composite indexes lead with `tenant_id` (e.g.,
  `(tenant_id, employee_id, date)` on `attendance_records`).
- Uniqueness is **per tenant** (e.g., `unique(tenant_id, employee_number)`).
- Partition high-volume, time-series tables (`attendance_records`,
  `audit_logs`, `notifications`) by month/range as data grows.
- Keep analytics off the transactional path (read replicas / OLAP later).

## 5. Data Protection

- **Sensitive columns** (`national_id`, `bank_account`, payroll amounts) are
  permission-gated at the app layer and encrypted at rest where applicable.
- Soft-delete tenant data; hard deletes and destructive migrations require
  approval (`CLAUDE.md` rule 2) with a tested rollback.
- Audit logs are append-only and never carry raw secrets (redact in
  `before`/`after`).

## 6. Not Yet Decided

- Final PK type (UUID vs ULID), exact partitioning cadence, and RLS policy
  details are pending the tenancy/database ADRs in
  [`DECISIONS.md`](DECISIONS.md).

> Reminder: **Do not create migrations yet.** This is a conceptual model only.
