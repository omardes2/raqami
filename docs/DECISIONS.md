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
