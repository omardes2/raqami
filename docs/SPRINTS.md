# Development Roadmap & Sprints — Raqmi Dawam

**Status:** Proposed roadmap (planning phase). Work proceeds **incrementally by
sprint** (`CLAUDE.md` rule 11). **Do not implement future sprints unless
explicitly requested** (rule 12). **Sprint 0 is NOT to be implemented yet.**

Sprint sizing is indicative; the owner sets the actual cadence.

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
1. **Application foundation** — project scaffolding, configuration, environment
   setup (`.env.example`, no secrets), base CI, coding standards.
2. **SaaS multi-tenancy** — tenant model, tenant resolution (subdomain/auth
   context), global tenant scope, RLS backstop, and enforced isolation.
3. **Authentication** — registration, email verification, login/logout, password
   reset, session/token handling, MFA capability for privileged roles.
4. **Companies (tenants)** — company/tenant entity and minimal onboarding to
   provision a tenant.
5. **Users** — user model, invitations, per-tenant user management, locale per
   user.
6. **Roles & permissions** — RBAC layer, permission catalog, system roles,
   policy checks, sensitive-field gating.
7. **Localization** — i18n framework, Arabic (RTL) + English (LTR), no
   hard-coded strings, locale-aware formatting.
8. **Audit logs** — append-only audit service capturing critical foundation
   events (auth, role/permission changes, tenant actions).
9. **Super Admin foundation** — central portal scaffold with the audited
   cross-tenant context and platform-scope permissions.
10. **Automated testing foundation** — test framework, CI gate, and the
    mandatory **tenant isolation** and **permission** test suites.

**Explicitly NOT in Sprint 0:** attendance, leave, tasks, payroll, billing
payments, reports, AI features, mobile API. Those come in later sprints.

**Definition of Done (Sprint 0):**
- A company can be provisioned as an isolated tenant.
- Users authenticate and are authorized via RBAC.
- Cross-tenant access is impossible and **proven by tests**.
- UI works in Arabic (RTL) and English (LTR) with no hard-coded strings.
- Critical actions are audited.
- Super Admin foundation exists (audited).
- CI runs tests including isolation & permission suites; no secrets in VCS.

> **Reminder:** Per the current task and `CLAUDE.md` rule 12, **do not implement
> Sprint 0 yet.** This document defines its scope for approval only.

---

## Later Sprints (summary scope)

- **Sprint 1 — Organization:** branches, departments (hierarchy), employees
  (incl. sensitive fields gated), teams.
- **Sprint 2 — SaaS billing:** plans, subscription lifecycle, card/bank/manual
  payments, invoices, dunning; gateway ADR accepted.
- **Sprint 3 — Attendance core:** work schedules, check-in/out (server-time),
  grace periods, daily status reconciliation.
- **Sprint 4 — Attendance advanced:** shifts (incl. overnight/rotating),
  overtime, GPS capture, geofencing (enforce/warn/off).
- **Sprint 5 — Leave management:** leave types/policies, balances/accruals,
  request→approval, payroll/attendance integration.
- **Sprint 6 — Tasks & teams:** task lifecycle, assignment/visibility,
  comments, notifications/reminders.
- **Sprint 7 — Payroll:** runs, calculation from attendance/leave/overtime,
  approval (separation of duties), immutable records, bilingual payslips.
- **Sprint 8 — Reports & notifications:** reporting/exports, notification
  channels (in-app/email, later SMS/push).
- **Sprint 9 — AI features:** assistant, insights/reports, task generation,
  workload analysis — permission-aware, tenant-isolated; provider ADR accepted.
- **Sprint 10 — Mobile API & hardening:** finalize versioned mobile API,
  performance/scale work (read replicas, partitioning), security hardening,
  observability.

---

## Cross-Sprint Backlog (ongoing)

- Performance & scale (partitioning of attendance/audit, read replicas,
  caching).
- Observability (logs, metrics, tracing, alerting).
- Compliance & data-residency work per approved regions.
- Documentation kept in sync with implementation.
- Backups/restore drills before production.
