# Development Roadmap & Sprints — Raqmi Dawam

**Status:** Roadmap with **owner-approved architecture** (2026-08-31, see
[`DECISIONS.md`](DECISIONS.md) ADR-001…018). Work proceeds **incrementally by
sprint** (`CLAUDE.md` rule 11). **Do not implement future sprints unless
explicitly requested** (rule 12). **Sprint 0 is defined and approved in scope but
is NOT to be implemented yet** — implementation waits for an explicit go.

Sprint sizing is indicative; the owner sets the actual cadence. The approved
stack is **Laravel + PostgreSQL + Redis + React (TypeScript)**, modular monolith,
API-first.

---

## Guiding Principles

- Foundation before features: tenancy, auth, RBAC, i18n, audit, and testing come
  first.
- Every sprint delivers something demonstrable and **tested**.
- Security and bilingual RTL/LTR are in-scope for every sprint, not bolted on.
- Decisions that constrain the future are recorded in `DECISIONS.md`.

---

## Roadmap Overview

| Sprint | Theme | Outcome |
|---|---|---|
| **0** | **Platform foundation** | Multi-tenant, authenticated, RBAC'd, localized, audited base + Super Admin foundation + testing foundation |
| **1** | Organization | Branches, departments, employees, teams |
| **2** | SaaS billing | Plans, subscriptions, payments (card/bank/manual), invoices |
| **3** | Attendance core | Schedules, check-in/out, grace, statuses |
| **4** | Attendance advanced | Shifts, overtime, GPS, geofencing |
| **5** | Leave management | Types, balances, request/approval workflow |
| **6** | Tasks & teams | Task lifecycle, assignment, visibility, notifications |
| **7** | Payroll | Runs, calculation, approval, payslips |
| **8** | Reports & notifications | Reporting, exports, notification channels |
| **9** | AI features | Assistant, insights, task generation, workload analysis |
| **10** | Mobile API & hardening | Mobile-ready API, performance, security hardening, scale |

Each sprint includes: automated tests, bilingual UI, permission enforcement,
audit logging for its critical actions, and relevant ADR updates.

---

## Sprint 0 — Platform Foundation (scope only; not to be implemented yet)

**Goal:** a secure, multi-tenant, localized, audited application skeleton that
every later sprint builds on. **This sprint is defined here but must not be
started until explicitly requested.**

**In scope:**
1. **Application foundation** — Laravel backend + React/TS SPA scaffolding,
   configuration, environment setup (`.env.example`, no secrets), base CI,
   coding standards, `.gitignore`, containerized dev setup.
2. **SaaS multi-tenancy (ADR-002)** — tenant model, tenant resolution
   (subdomain/auth context), **Laravel tenant context + global query scopes +
   PostgreSQL RLS**, and enforced isolation. **ULID** primary keys (ADR-008).
3. **Authentication** — registration, email verification, login/logout, password
   reset, session/token handling, MFA capability for privileged roles.
4. **Companies (tenants)** — company/tenant entity and minimal onboarding to
   provision a tenant.
5. **Users** — user model, invitations, per-tenant user management, locale per
   user.
6. **Roles & permissions (ADR-015)** — RBAC layer, permission catalog, system
   roles, policy checks, sensitive-field gating, and **Company / Branch /
   Department-Team scoping** so managers are bounded to their scope.
7. **Localization (ADR-012)** — i18n framework, Arabic (RTL) + English (LTR) from
   day one, no hard-coded strings, locale-aware formatting.
8. **Audit logs** — append-only audit service capturing critical foundation
   events (auth, role/permission changes, tenant actions).
9. **Super Admin foundation** — central portal scaffold with the audited
   cross-tenant context and platform-scope permissions.
10. **Automated testing foundation** — test framework, CI gate, and the
    mandatory **tenant isolation** and **permission** test suites.
11. **Provider-abstraction seams (structure only, no integrations)** — define the
    **Payment Gateway** (ADR-010) and **AI Provider** (ADR-011) interfaces, and
    scaffold **GDPR-ready** hooks (ADR-013: export/deletion/retention/consent
    tables) so later sprints plug in without rework. No provider integrations and
    no AI/payment logic are built in Sprint 0.

**Explicitly NOT in Sprint 0:** attendance, leave, tasks, payroll, billing
payments/online gateways, reports, AI features, mobile API, country payroll rule
providers, table partitioning. Those come in later sprints (partitioning is a
future scaling strategy only — ADR-009).

**Definition of Done (Sprint 0):**
- A company can be provisioned as an isolated tenant (ULID keys).
- Users authenticate and are authorized via RBAC **with Company/Branch/Team
  scope**.
- Cross-tenant access is impossible and **proven by automated isolation tests**
  (tenant context + global scopes + RLS).
- UI works in Arabic (RTL) and English (LTR) with no hard-coded strings.
- Critical actions are audited.
- Super Admin foundation exists (audited).
- Payment Gateway and AI Provider abstraction interfaces exist (no integrations);
  GDPR-ready seams scaffolded.
- CI runs tests including isolation & permission suites; no secrets in VCS
  (`.env.example` placeholders only).

> **Reminder:** Per the current task and `CLAUDE.md` rule 12, **do not implement
> Sprint 0 yet.** This document defines its scope for approval only.

---

## Later Sprints (summary scope)

- **Sprint 1 — Organization:** branches, departments (hierarchy), employees
  (incl. sensitive fields gated), teams — all scope-aware (ADR-015).
- **Sprint 2 — SaaS billing:** plans, subscription lifecycle, **Payment Gateway
  abstraction** with **bank transfer + cash/manual** flows, invoices, dunning
  (ADR-010). Online card provider integration is a later sprint.
- **Sprint 3 — Attendance core (ADR-017):** work schedules, web/mobile
  check-in/out (server-time), grace periods, daily status reconciliation.
- **Sprint 4 — Attendance advanced (ADR-017):** shifts (incl.
  overnight/rotating), overtime, GPS capture, geofencing (enforce/warn/off).
  Biometric/face/kiosk are deferred.
- **Sprint 5 — Leave management:** leave types/policies, balances/accruals,
  request→approval, payroll/attendance integration.
- **Sprint 6 — Tasks & teams (ADR-016):** tasks, **subtasks, Kanban workflow**,
  priorities, due dates, comments, **attachments**, assignees, teams,
  notifications/reminders. Dependencies/Gantt deferred.
- **Sprint 7 — Payroll (ADR-014):** **generic Payroll Core** — runs, calculation
  from attendance/leave/overtime, approval (separation of duties), immutable
  records, bilingual payslips. **First country rule provider** added here (or a
  dedicated follow-up), never hard-coded.
- **Sprint 8 — Reports & notifications:** reporting/exports, notification
  channels (in-app/email, later SMS/push).
- **Sprint 9 — AI features (ADR-011):** assistant, insights/reports, task
  generation, workload analysis — behind the **AI Provider abstraction**,
  permission-/scope-aware, tenant-isolated, no autonomous sensitive/destructive
  actions.
- **Sprint 10 — Mobile API & hardening:** finalize versioned mobile API,
  security hardening, observability, and — **only as load justifies** — the
  deferred scaling strategies (read replicas, table partitioning, service
  extraction) per ADR-009/018.

---

## Cross-Sprint Backlog (ongoing)

- Performance & scale — **future strategies only** (partitioning of
  attendance/audit, read replicas, caching), introduced when load justifies
  (ADR-009/018).
- Observability (logs, metrics, tracing, alerting).
- GDPR-ready operations maturing over time (export/deletion/retention/consent —
  ADR-013) and data-residency work per approved regions.
- Additional country payroll rule providers (ADR-014).
- Additional payment gateway drivers, incl. regional (ADR-010).
- Documentation kept in sync with implementation.
- Backups/restore drills before production.

---

## Sprint 1 — Organization & Employees (IMPLEMENTED on feature branch)

Delivered on `feature/sprint-1-organization-employees` (not merged to `main`):

- **Organization:** branches, hierarchical departments (cycle-prevented), teams
  + memberships, job titles — tenant-scoped CRUD with archive-not-delete and
  dependency guards.
- **Employees:** HR record **separate from User** (optional link), auto
  `EMP-000123` numbering (per-tenant unique), employment status/type, direct
  manager (self-management + cycle prevented), organizational transfers
  (transactional, history + audit), emergency contacts, private documents,
  contracts (no compensation), append-oriented HR history.
- **Authorization:** ADR-015 scopes made operational against real Branch/
  Department/Team entities; `EmployeeScopeResolver` (with department-subtree
  expansion) enforces scope at query + row level; scope-safe 404 for IDOR;
  sensitive fields gated by `employees.view_sensitive`.
- **RLS** extended to all 10 new tenant-owned tables. **Frontend:** Organization
  section + Employees list/create/detail(tabs) + org CRUD, AR/EN + RTL/LTR,
  responsive.
- **Out of scope (not implemented):** attendance, shifts, leave, payroll,
  tasks, billing, AI, timesheets, biometric/kiosk, country labor rules.

Sprint 1 is complete on its branch; Sprint 2 has **not** started.
