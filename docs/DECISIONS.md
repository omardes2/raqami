# Architecture Decision Records — Raqmi Dawam

This log records important product and architecture decisions (`CLAUDE.md` rule
13). Decisions that constrain the future — stack, tenancy, database strategy,
providers — are captured here as **ADRs**.

**Statuses:** `Proposed` · `Accepted` · `Superseded` · `Rejected`.

> **Owner approval — 2026-08-31.** The project owner reviewed the foundation
> documentation and approved the architecture decisions below. ADR-001…008 are
> now **Accepted**, and ADR-009…017 record the additional approved decisions.
> Sprint 0 implementation has **not** started.

---

## ADR Template

```
## ADR-NNN: <Title>
- Status: Proposed | Accepted | Superseded | Rejected
- Date: YYYY-MM-DD
- Context: <why a decision is needed>
- Options: <options considered>
- Decision: <what we chose>
- Consequences: <trade-offs, follow-ups>
- Approved by: <owner> (date)
```

---

## ADR-001: Backend framework — Laravel (PHP)
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Need a productive, mature framework for a feature-rich SaaS
  (auth, queues, policies, migrations, testing) with strong multi-tenancy
  support.
- **Options:** Laravel (PHP), NestJS (Node/TS), Django (Python), Rails (Ruby).
- **Decision:** **Laravel** for velocity, ecosystem, and SaaS/tenancy maturity.
- **Consequences:** PHP toolchain; strong batteries-included features; team must
  know Laravel conventions.
- **Approved by:** Project Owner (2026-08-31)

## ADR-002: Multi-tenancy strategy — shared DB + `tenant_id` + RLS
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Must scale to thousands of tenants / hundreds of thousands of
  employees with complete isolation at reasonable ops cost.
- **Options:** (A) shared DB/schema + `tenant_id`; (B) schema-per-tenant;
  (C) database-per-tenant.
- **Decision:** **Pattern A** — a single **shared PostgreSQL database with a
  shared schema**. **Every tenant-owned record must include `tenant_id`.**
  Isolation is enforced through **(1) a Laravel tenant context, (2) global query
  scopes, (3) PostgreSQL Row-Level Security (RLS), and (4) automated
  cross-tenant isolation tests.** Cross-tenant data access is treated as a
  **critical security vulnerability**. Dedicated database-per-tenant is reserved
  as a future option for exceptional enterprise/regulated tenants only.
- **Consequences:** Lowest ops cost, highest scale; isolation must be rigorously
  enforced and tested at every layer.
- **Approved by:** Project Owner (2026-08-31)

## ADR-003: Primary database — PostgreSQL
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Need robust relational DB with RLS, JSONB, and strong indexing
  for tenancy and high-volume attendance/audit data.
- **Options:** PostgreSQL, MySQL/MariaDB.
- **Decision:** **PostgreSQL** (RLS support is decisive for tenant isolation).
- **Consequences:** Postgres-specific features (RLS) become part of the design.
- **Approved by:** Project Owner (2026-08-31)

## ADR-004: API-first + SPA frontend (React/TypeScript)
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Three portals plus a **future mobile API** argue for an
  API-first backend consumed by SPA clients.
- **Options:** Server-rendered; SPA + JSON API; hybrid.
- **Decision:** **API-first (versioned REST/JSON)** with a **React (TypeScript)**
  SPA for the SaaS application; RTL/LTR via an i18n layer.
- **Consequences:** API contracts and versioning discipline; reusable API for
  mobile.
- **Approved by:** Project Owner (2026-08-31)

## ADR-005: Cache/queue — Redis; async workers
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Heavy work (payroll, reports, AI, notifications, geofence checks)
  must run off the request path.
- **Decision:** **Redis** for cache/sessions/queues; queue workers for async
  jobs.
- **Consequences:** Additional infra component; scalable background processing.
- **Approved by:** Project Owner (2026-08-31)

## ADR-006: Object storage — S3-compatible
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Store payslips, invoices, exports, proof-of-payment, attachments.
- **Decision:** **S3-compatible** storage with private access + signed URLs.
- **Approved by:** Project Owner (2026-08-31)

## ADR-007: Modular monolith to start (no microservices)
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Balance delivery speed with future scale.
- **Decision:** Build a **modular monolith**, **API-first**. **Microservices are
  explicitly out of scope at this stage.** Heavy/independent concerns (AI,
  reporting, payroll workers) may be extracted into services later only if load
  demands.
- **Approved by:** Project Owner (2026-08-31)

## ADR-008: Primary key type — ULID
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Avoid enumeration, keep keys sortable and generation-friendly.
- **Decision:** Use **ULID** for application entities **unless there is a strong
  technical reason not to** (documented per exception).
- **Consequences:** Lexicographically sortable, time-ordered identifiers; store
  consistently (e.g., 26-char string or 16-byte binary) across the schema.
- **Approved by:** Project Owner (2026-08-31)

## ADR-009: No table partitioning in the initial system
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** High-volume tables (attendance, audit) will eventually grow large,
  but partitioning adds complexity that is premature now.
- **Decision:** **Do not implement table partitioning initially.** Document
  partitioning (and read replicas / OLAP) as a **future scaling strategy only**.
  Design indexes so partitioning can be introduced later without a data model
  rewrite.
- **Approved by:** Project Owner (2026-08-31)

## ADR-010: Payment Gateway abstraction (provider-agnostic billing)
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Billing must support multiple methods and future regional gateways
  without coupling to any one provider.
- **Decision:** Introduce a **Payment Gateway abstraction**. Billing logic must
  **not** be coupled directly to Stripe, Cybersource, or any single provider.
  The abstraction must support: **card payment gateway, bank transfer,
  cash/manual payment, and future regional payment gateways.** Actual online
  gateway integration is deferred to a later sprint.
- **Consequences:** A driver/adapter interface with pluggable providers; manual
  and bank-transfer flows implemented without an online gateway first.
- **Approved by:** Project Owner (2026-08-31)

## ADR-011: AI Provider abstraction and AI action boundaries
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Avoid lock-in to a single AI provider and constrain what AI may do.
- **Decision:** Create an **AI Provider abstraction**; business logic must never
  depend directly on a single AI provider. **AI must not autonomously** modify
  payroll, approve payroll, change attendance records, approve leave, modify
  financial transactions, or perform destructive actions. Any such future
  AI-assisted action **requires explicit authorized user confirmation**.
- **Approved by:** Project Owner (2026-08-31)

## ADR-012: Internationalization — Arabic RTL + English LTR from day one
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Bilingual support is a core product property.
- **Decision:** **Arabic (RTL) and English (LTR) are mandatory from the first
  implementation.** **No UI text may be hard-coded**; all strings come from i18n
  resources.
- **Approved by:** Project Owner (2026-08-31)

## ADR-013: GDPR-ready compliance posture
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** The platform should be designed for data-protection compliance
  without premature complexity.
- **Decision:** Design the platform to be **GDPR-ready**. Architecture must
  support: **account/data export, data deletion workflows, data retention
  policies, consent tracking where applicable, audit logs, and sensitive-data
  protection.** Do **not** implement unnecessary compliance complexity yet.
- **Approved by:** Project Owner (2026-08-31)

## ADR-014: Generic Payroll Core (country rules are modular)
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Payroll must serve many countries without baking one country's
  rules into the core.
- **Decision:** Build a **generic Payroll Core**. **Do not hard-code taxes or
  statutory payroll rules for any single country.** Country-specific rules are
  implemented later through **modular country rule providers** plugged into the
  core.
- **Approved by:** Project Owner (2026-08-31)

## ADR-015: Scoped authorization — Company / Branch / Department-Team
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Managers must be limited to their assigned part of the
  organization.
- **Decision:** The authorization architecture must support **Company scope,
  Branch scope, and Department/Team scope.** An authorized manager may only
  access users and resources **within their assigned scope.**
- **Consequences:** Permissions carry a scope dimension in addition to the
  action; scope checks join the tenant + permission checks.
- **Approved by:** Project Owner (2026-08-31)

## ADR-016: Tasks V1 feature set
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Define what ships in the first Tasks release.
- **Decision:** Tasks V1 includes **tasks, subtasks, Kanban workflow,
  priorities, due dates, comments, attachments, assignees, and teams.**
  **Advanced dependencies and Gantt charts are deferred.**
- **Approved by:** Project Owner (2026-08-31)

## ADR-017: Attendance V1 methods
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Define attendance capture methods for the first release.
- **Decision:** Primary attendance methods are **web/mobile check-in, GPS, and
  geofencing.** **Biometric devices, face recognition, and kiosk integrations
  are future features.**
- **Approved by:** Project Owner (2026-08-31)

---

## ADR-018: Scaling posture — simple scalable architecture first
- **Status:** Accepted
- **Date:** 2026-08-31
- **Context:** Must target thousands of companies and hundreds of thousands of
  employees while avoiding premature infrastructure complexity.
- **Decision:** Design for that scale but **prefer a simple, scalable
  architecture first** (stateless app tier, Redis, async workers, well-indexed
  Postgres). Defer partitioning, read replicas, OLAP stores, and service
  extraction until real load justifies them (see ADR-009).
- **Approved by:** Project Owner (2026-08-31)

## ADR-019: Attendance sessions with a derived daily aggregate
- **Status:** Accepted
- **Date:** 2026-09-01
- **Context:** Sprint 4 needs split shifts and multiple check-ins per day
  without breaking the Sprint 3 one-record-per-day model or its computed history.
- **Decision:** Introduce `attendance_sessions` as the unit of check-in/out
  (multiple closed per `work_date`, at most one open per employee via a partial
  unique index + advisory lock). `attendance_records` becomes the **daily
  aggregate**, recomputed from its sessions by a single `AttendanceRecordAggregator`
  and carrying a `version` counter for optimistic concurrency. Split shifts use
  `work_schedule_segments`; rotation uses `cycle_length_days` + `anchor_date`
  (weekday reinterpreted as cycle-day-index). All migrations are additive with
  idempotent backfills — no Sprint 3 data is dropped. `allow_multiple_sessions`
  defaults off to preserve Sprint 3 semantics.
- **Approved by:** Project Owner (2026-09-01)

## ADR-020: Materialization, overtime, and anomalies — server-owned, neutral, no money
- **Status:** Accepted
- **Date:** 2026-09-01
- **Context:** Advanced attendance must derive absence/holiday/weekend state,
  track overtime, and surface irregularities — without straying into payroll,
  leave business logic, or accusatory automation.
- **Decision:** (1) Daily state is **materialized** server-side
  (`attendance:process-daily`): absence only **after** a configurable cutoff
  (never at midnight), holiday overrides absence, a real punch is never
  overwritten, idempotent per-tenant. (2) **Overtime** keeps raw
  `calculated_minutes` separate from reviewer `approved_minutes`, forbids
  self-approval and over-approval-without-override, uses optimistic concurrency,
  and performs **no monetary conversion**. (3) **Anomalies** are neutral,
  rule-based, deduplicated findings (`suspicious_location_change`, never
  "fraud") that never trigger automatic disciplinary action. (4) Remote/field/
  off-day attendance requires an authorized `attendance_exception`; employees
  never self-declare. Leave remains out of scope (a future integration hook only).
- **Approved by:** Project Owner (2026-09-01)

---

## Final Architecture Decisions (summary)

| # | Area | Decision | ADR |
|---|---|---|---|
| 1 | Backend | Laravel (PHP) | 001 |
| 2 | Database | PostgreSQL | 003 |
| 3 | Cache/Queue | Redis + async workers | 005 |
| 4 | Frontend | React + TypeScript (SPA) | 004 |
| 5 | Architecture | Modular monolith, API-first, **no microservices** | 007 |
| 6 | Multi-tenancy | Shared DB + shared schema; `tenant_id` everywhere; tenant context + global scopes + RLS + isolation tests | 002 |
| 7 | Primary keys | ULID (unless strong reason not to) | 008 |
| 8 | Partitioning | **Not now**; future scaling strategy only | 009 |
| 9 | Payments | Payment Gateway abstraction (card/bank/manual + future regional) | 010 |
| 10 | AI | AI Provider abstraction; no autonomous sensitive/destructive actions | 011 |
| 11 | i18n | Arabic RTL + English LTR from day one; no hard-coded text | 012 |
| 12 | Compliance | GDPR-ready (export/deletion/retention/consent/audit/sensitive-data) | 013 |
| 13 | Payroll | Generic Payroll Core; country rules are modular providers | 014 |
| 14 | Permission scopes | Company / Branch / Department-Team scoping | 015 |
| 15 | Tasks V1 | tasks, subtasks, Kanban, priorities, due dates, comments, attachments, assignees, teams | 016 |
| 16 | Attendance V1 | web/mobile check-in, GPS, geofencing | 017 |
| 17 | Scaling | Simple scalable architecture first | 018 |
| 18 | Object storage | S3-compatible + signed URLs | 006 |

---

## Sprint 0 Implementation Notes

Implementation-level decisions made while building Sprint 0. These are
**consistent with**, and do not reinterpret, the owner-approved ADRs above.

- **Framework versions:** Laravel 13 (current stable) + React 19/TypeScript on
  Vite. First-party SPA auth via **Laravel Sanctum** (HTTP-only cookie/session);
  `HasApiTokens` retained on `User` so future mobile/API token auth needs no
  rework (ADR-004).
- **Testing framework:** **PHPUnit** (Laravel default) — integrates cleanly with
  the selected Laravel version; tenant-isolation and authorization suites run
  against PostgreSQL as a non-superuser role so RLS is genuinely exercised.
- **RLS mechanism:** tenant context is carried in PostgreSQL session GUCs
  `app.tenant_id`, `app.user_id`, and `app.platform_readonly`, set/reset by a
  `TenantContext` service. Tables use `ENABLE` + `FORCE ROW LEVEL SECURITY`.
  The app-layer global scope and RLS are two independent layers (ADR-002).
- **ULID storage:** stored as `char(26)` (the approved 26-char representation,
  ADR-008); no binary optimization.
- **Identity vs membership:** `users` is a global identity table (no tenant_id);
  `tenant_memberships` links users to tenants many-to-many, so one person may
  belong to several companies. User identity stays separate from the future
  Employee/HR record.
- **Super Admin:** a separate `platform_admins` table + `platform` auth guard,
  entirely outside tenant RBAC. Cross-tenant reads happen only through an
  audited, explicit `platform_readonly` context; writes never bypass tenant
  scope (ADR-004/SECURITY).
- **Audit immutability:** append-only enforced by RLS (no UPDATE/DELETE policy)
  **and** a database trigger that rejects mutation even for a superuser.
- **Authorization scopes:** role assignments carry `scope_type`
  (company/branch/department/team) + nullable `scope_id`. Company scope is
  active now; branch/department/team `scope_id`s have no FK because those tables
  do not exist yet (ADR-015). Owner holds all Sprint 0 permissions but does
  **not** bypass tenant isolation.
- **Seams only:** `PaymentGateway` and `AiProvider` contracts ship with inert
  default drivers that make no external calls (ADR-010/011). GDPR tables exist
  as concept-level foundations (ADR-013).
- **Queues:** a `TenantAware` job trait + `ApplyTenantContext` middleware require
  jobs to carry explicit tenant context; workers never rely on ambient state.

## Remaining Unresolved Decisions

These are **not** required to start Sprint 0, but must be decided before the
sprint that needs them:

1. **Card gateway provider(s)** and supported regions/currencies — needed for
   the online-payment sprint (billing abstraction is provider-agnostic, so this
   does not block Sprint 0). (`SAAS-BILLING.md`)
2. **AI provider(s)** and data-handling/training/residency terms — needed for
   the AI sprint (abstraction lets the choice be deferred). (`AI-FEATURES.md`)
3. **Target regions & specific compliance certifications** beyond GDPR-ready
   posture. (`SECURITY.md`)
4. **First country payroll rule provider(s)** (which country/countries, statutory
   rules, rounding, multi-currency payroll). (`PAYROLL.md`)
5. **Attendance overtime policy defaults** and legal rounding per region.
   (`ATTENDANCE.md`)
6. **Notification channels beyond in-app/email** (SMS/push provider) — later
   sprint. (`SPRINTS.md`)
7. **ULID storage representation** (26-char string vs 16-byte binary) — a
   Sprint 0 implementation detail to confirm at kickoff. (`DATABASE.md`)

## Sprint 1 Implementation Notes

Implementation-level decisions for Sprint 1 (Organization & Employees),
consistent with the approved ADRs; no ADR is changed.

- **User ≠ Employee:** `users` (auth identity) and `employees` (HR record) are
  distinct tables. `employees.user_id` is nullable; linking is an explicit,
  validated, audited action. Reinforces ADR-002 identity/membership design.
- **Scoped authorization operationalized (ADR-015):** role assignments target
  real Branch/Department/Team ids; `EmployeeScopeResolver` translates grants to
  query/row constraints, expanding a department scope to its subtree. Route
  gates: `permission.any:<key>` for scoped resources, `permission:<key>` for
  org-structure management.
- **Archive over delete:** organizational/employee records are archived
  (status + soft delete on employees); destructive delete is avoided and guarded
  by dependency checks (CLAUDE.md rule 2).
- **Two distinct logs:** `audit_logs` (security, immutable) vs
  `employee_history_events` (HR timeline). Deliberately not merged.
- **Contracts carry no compensation (ADR-014):** contract foundation only; a
  future permission-protected compensation domain is out of Sprint 1 scope.
- **Documents:** private S3-compatible disk, metadata-only rows, authorized
  streamed downloads / short-lived signed URLs — never public URLs.
- **Middleware ordering:** `ResolveTenant` runs before `SubstituteBindings` so
  route-model binding executes under the correct tenant/RLS context.
- **Employee number:** default `EMP-000123` generator; company-configurable
  formats deferred. ULID remains the internal PK (ADR-008).

## Sprint 2 Implementation Notes

Implementation-level decisions for Sprint 2 (SaaS Billing & Subscriptions),
consistent with the approved ADRs; no approved ADR is changed.

- **Billing domain split (platform-global vs tenant-linked):** `plans`,
  `plan_features`, `coupons`, and `bank_accounts` are **platform-global**
  configuration (no `tenant_id`, no RLS) — never duplicated per tenant.
  `subscriptions`, `subscription_changes`, `subscription_events`,
  `billing_profiles`, `invoices`, `invoice_items`, `payments`,
  `bank_transfer_submissions`, `coupon_redemptions`, and `billing_counters` are
  **tenant-linked** (`tenant_id` + FORCE RLS, same `tenant_isolation` +
  `platform_readonly` policies as Sprint 0/1). This **refines** the conceptual
  note in `DATABASE.md` that placed subscriptions/invoices/payments in the
  "central" context: they belong to the TENANT and are RLS-isolated, while the
  Super Admin portal reads them cross-tenant only through the audited
  platform read-only context. Reinforces ADR-002; does not change it.
- **Subscription belongs to the tenant, not a user:** one primary subscription
  per tenant (`unique(tenant_id)`); users act on it only via `billing.*`
  permissions.
- **Status as value objects:** subscription/invoice/payment statuses are PHP
  enums; `SubscriptionStatus` owns the allowed-transition map and lifecycle is
  driven by `SubscriptionManager` (invalid transitions rejected). No arbitrary
  status strings.
- **Money:** integer minor units everywhere; one currency per invoice/payment;
  no FX conversion in Sprint 2. Totals are always computed server-side.
- **Downgrades never delete data:** recorded as a scheduled `subscription_change`
  applied at period end, with an over-cap warning when current usage exceeds the
  target plan; upgrades apply immediately.
- **Payment application is transactional + idempotent:** `PaymentService`
  locks the invoice, enforces currency match, rejects overpayment (no account
  credits in Sprint 2), supports partial payments, and activates/renews the
  subscription on full payment. Bank-transfer approval and manual/cash payment
  run inside the target tenant's context (RLS-safe) even though the actor is a
  platform admin, with a row-lock + status guard preventing double application.
- **Employee-limit entitlement (ADR-015 adjacent):** enforced at the employee
  creation entry point via `EntitlementService`; countable employees exclude
  `terminated`/`archived`; a tenant with no active plan is unlimited (fail-open).
- **Payment provider abstraction unchanged (ADR-010):** no real card provider
  integrated; `WebhookIngestionService` + `idempotency_records` establish the
  idempotent webhook seam without a public endpoint or provider SDK.
- **Invoice numbering:** per-tenant `INV-YYYY-######` via an atomic
  `INSERT ... ON CONFLICT ... RETURNING` counter (`billing_counters`);
  never exposes the DB id; concurrency-safe.

## Sprint 2 — Commercial Hardening Notes

Final commercial-hardening decisions for Sprint 2 (consistent with the approved
ADRs; none is changed):

- **Fail-CLOSED entitlements.** Product entitlements require an explicit USABLE
  commercial state (trialing / active / grace_period). No subscription, expired,
  suspended, or canceled grant nothing — there is no implicit unlimited fallback.
  Onboarding bootstraps a trial from the single **platform-configured default
  trial plan** (`plans.is_default_trial`, partial-unique "one active default")
  when one exists; otherwise the tenant stays fail-closed until it picks a plan.
  Billing/account/recovery routes never gate on subscription usability.
- **Upgrades are PAYMENT-GATED.** A plan upgrade records a *pending*
  `subscription_change` linked to an invoice; the new plan/limits apply only when
  that invoice is fully paid (`SubscriptionManager::applyPendingChangeForInvoice`,
  invoked by `PaymentService`). Downgrades stay scheduled at period end and never
  delete data. No card proration.
- **Reactivation.** A terminal (canceled/expired) subscription is reactivated via
  an explicit, payment-gated purchase on the same single per-tenant row — never a
  silent restart, never a second free trial; history is preserved.
- **Invoice numbers are GLOBALLY unique.** Numbering moved from the per-tenant
  `billing_counters` (removed) to a platform-global `invoice_number_sequences`
  table (atomic `INSERT ... ON CONFLICT ... RETURNING`); `invoices.invoice_number`
  now carries a **global** unique constraint. Still `INV-YYYY-######`, no DB id
  exposed.
- **Employee-limit concurrency.** The entitlement check and the insert run in one
  transaction under a per-tenant PostgreSQL **advisory xact lock**, so concurrent
  creates cannot exceed the plan cap.
- **Currency exponents.** `CurrencyMetadata` (+ config `currency_exponents`) and a
  frontend mirror format minor units by ISO exponent (JOD = 3, others = 2).
  Authoritative arithmetic stays in integer minor units.
- **Lifecycle processor.** `SubscriptionLifecycleProcessor` + the idempotent
  `billing:process-lifecycle` command process due trial/grace expiry, scheduled
  cancellation, and scheduled downgrade per-tenant with failure isolation. No
  cron is configured here.
- **Client-safe errors.** Invalid commercial transitions (terminal change/cancel,
  no pending cancellation, cross-currency) surface as localized HTTP 422, never a
  raw 500.

## Sprint 3 Implementation Notes

Implementation-level decisions for Sprint 3 (Attendance Core), consistent with
the approved ADRs (esp. ADR-017); no approved ADR is changed. Governing
principle: **the client is never trusted to decide the result.** The client
sends raw facts (GPS coordinates, punch intent); the SERVER decides the instant,
the schedule, the geofence membership, lateness, worked time, and status.

- **Server-authoritative time.** All timestamps are stored in **UTC**; the server
  uses its own clock for every punch instant. `work_date` and per-record
  `timezone` carry the schedule-timezone context so daily boundaries are computed
  in the schedule's zone, not the client's.
- **Time model V1 (work_date + overnight).** `work_date` is the
  schedule-timezone local date of the punch. An overnight window
  (`end_time <= start_time`) extends `scheduled_end_at` into the **following**
  day; it does not reach back to the previous calendar day. Deterministic and
  simple by design; richer rotating-shift resolution is deferred to Sprint 4.
- **Records vs events.** `attendance_records` is the computed **daily rollup**
  (one per employee per `work_date`); `attendance_events` is the **append-only
  raw punch log** recording exactly what the client sent and what the server
  decided (matched location, distance, inside/outside, accuracy). Records are
  derived from events, never the other way round.
- **Snapshot on check-in.** Schedule boundaries + grace/break/overtime are
  **snapshot** onto the record at check-in (`scheduled_start_at`,
  `scheduled_end_at`, `grace_minutes`, …). Check-out and approved corrections
  recompute from that snapshot, so later schedule edits never rewrite closed
  history.
- **Single deterministic `ScheduleResolver`.** One authority resolves which
  schedule applies: precedence **employee > team > department (deepest ancestor
  first) > branch > company**, with a fully deterministic tie-break (priority,
  then effective_from, then created_at, then id). Every attendance calculation
  flows through it, so precedence is applied exactly once, everywhere.
- **Single `AttendanceCalculator`.** All minute math (late / early-leave /
  overtime / worked, and status) is a pure function of (snapshot, check-in,
  check-out) — no DB, no clock, no client input — so payroll-relevant numbers are
  consistent and unit-testable.
- **Backend geofencing (Haversine).** `GeofenceService` computes great-circle
  distance server-side and decides inside/outside against the nearest active
  location's radius, with optional per-location accuracy gating. Coordinates are
  decimals (no float drift). The client's claimed position is only an input.
- **Concurrency + idempotency.** Check-in/out run in one transaction guarded by a
  per-employee PostgreSQL **advisory xact lock** plus row locks; a partial unique
  index enforces **at most one open record per employee**. Retries are idempotent
  via a client-supplied `client_request_id` (partial unique index on
  `attendance_events` + replay), so a re-sent punch returns the same record.
- **Eligibility.** Only `active` / `onboarding` / `probation` employees may
  record attendance — a hard server rule (`AttendanceEligibility`), independent
  of any UI. Self-service is gated by an authenticated, **employee-linked** user,
  not an RBAC permission.
- **Controlled corrections (segregation of duties).** A change to a recorded day
  is **requested**, then reviewed by a **different** person — no self-approval.
  Approval recomputes from snapshot and keeps a full before/after trail; manual
  entry (`is_manual`, `source=manual`) is an authorized, audited action.
- **Sensitive GPS.** Precise coordinates are exposed only to the employee viewing
  their **own** record or to a user holding `attendance.view_location` **within a
  scope covering that employee** (scope-aware, NB-1). Everyone else sees the
  derived inside/outside flag only.
- **FORCE RLS on all attendance tables.** All eight tenant-owned attendance
  tables carry `tenant_id` + FORCE ROW LEVEL SECURITY with the same
  `tenant_isolation` + `platform_readonly` policies as Sprint 0/1/2; proven by
  raw-SQL cross-tenant tests.
- **Explicit operations, not generic CRUD.** The API exposes named operations
  (check-in, check-out, manual entry, assign schedule, review correction, …);
  there is no blanket attendance CRUD that could bypass the server-decides rule.
- **Out of Sprint 3 scope.** Payroll/overtime pay, leave business flows (a future
  hook only), tasks, AI, biometric/face/kiosk hardware, rotating-shift planners,
  and labor-law rounding remain deferred (see ADR-017 / SPRINTS).

## ADR-021: Leave — integer-minute ledger, coverage vs consumption, `on_leave`
- **Status:** Accepted
- **Date:** 2026-09-02
- **Context:** Sprint 5 needs a global-SaaS leave system that integrates with the
  Sprint 3/4 attendance engine without money, country rules, or a notification
  transport, and without floating-point balance errors.
- **Decision:** (1) **Integer minutes** are the canonical unit; a day of leave is
  the employee's *scheduled* minutes (no global 8h). (2) Balances use an
  **immutable ledger** (`leave_balance_transactions`, append-only) as the source of
  truth with a transactionally-maintained projection (`leave_balances`); the
  reservation→usage conversion deducts availability exactly once. (3) Policies
  declare a **consumption basis** (`scheduled_minutes` default, or
  `nominal_calendar_day` with `nominal_day_minutes`); `count_holidays` /
  `count_non_working_days` require the nominal basis (contradiction-guarded).
  (4) `leave_request_days` snapshots **both** balance **consumption** and attendance
  **coverage** (UTC coverage intervals), freezing the effect at submission.
  (5) Half-day is `full_day|first_half|second_half`, geometric over work minutes
  (`ceil(T/2)`); no arbitrary hourly leave in V1, but the interval model is
  hourly-ready. (6) Approval steps are **snapshotted** (direct_manager →
  department_manager → HR pool; Team Lead never automatic; HR pool is an RBAC set);
  self-approval is impossible. (7) A single **`LeaveResolver`** is attendance's only
  leave dependency; **no `attendance_records` schema change** (leave↔attendance is
  many-to-many). (8) `leave.negative_override` and `leave.attachments.view_sensitive`
  are distinct privileges. (9) Accrual/carry/expiry are ledger-based, idempotent,
  scheduler-ready (no cron). No money (Sprint 7), no country rules, no notification
  transport (Sprint 8).
- **Approved by:** Project Owner (2026-09-02, D1–D7 + Corrections A/B)

## ADR-022: Tasks & Teams — optional projects, stable scope, central visibility

- **Status:** Accepted (Sprint 6).
- **Context:** Companies need task & team collaboration without a generic
  workflow/BPM platform, and without duplicating the existing Organization team
  model or coupling to Leave/Attendance/Payroll.
- **Decision:** (1) **Reuse** `Team`/`TeamMembership`/`Department`/`Branch`/
  `Employee`; no new team concept. (2) **Projects are optional** — a task is
  standalone or in a project. (3) Placement uses a single stable
  `(scope_type, scope_id)` (company|branch|department|team, ADR-015 semantics);
  standalone tasks carry their own stable scope, project tasks inherit the
  project's — DB-enforced XOR. (4) A single **`TaskVisibilityResolver`** is the
  authority for all intra-tenant visibility (out-of-scope → scope-safe 404), with
  a **members_only** project ceiling that ordinary org scope cannot bypass; reports
  and workload run through it so hidden counts never leak. (5) **Project-local
  authority** (`project_memberships` manager|member; owner = `owner_employee_id`)
  is bounded to one project and never escalates to tenant settings, other projects,
  or company reports; governance requires owner/`projects.manage`. (6) Multiple
  assignees with **at most one primary** (DB partial-unique); assignees are
  Employees, collaboration identities are Users (an Employee may have no User).
  (7) **Status category** is the semantic truth (no `is_terminal`); `done` sets
  `completed_at`, `cancelled` does not; category locks once referenced; one active
  default (bootstrap by immutable `bootstrap_key`). (8) **Kanban** manual ordering
  (`board_rank`) is project-top-level only, sparse bigint with synchronous
  single-column renormalization (no cron, no floats). (9) Creator-scoped
  idempotency (fingerprint; mismatch → 409) and optimistic `version` (stale → 409)
  on tasks and comments; soft-delete comments. (10) `task_activity_events` is an
  append-only user timeline (metadata never carries bodies/keys/secrets), separate
  from `AuditLogger`. (11) FORCE RLS on all 11 task tables. No labels, no
  dependencies, no Leave/Attendance/Payroll coupling, no AI, no notification
  transport (Sprint 8) — domain events only.
- **Approved by:** Project Owner (2026-09-02, D1–D9 + Corrections A–H)
