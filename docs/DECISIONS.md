# Architecture Decision Records — Raqmi Dawam

This log records important product and architecture decisions (`CLAUDE.md` rule
13). Decisions that constrain the future — stack, tenancy, database strategy,
providers — are captured here as **ADRs**.

**Statuses:** `Proposed` · `Accepted` · `Superseded` · `Rejected`.
Nothing here is implemented yet — the entries below are **Proposed** and require
owner approval.

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
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Need a productive, mature framework for a feature-rich SaaS
  (auth, queues, policies, migrations, testing) with strong multi-tenancy
  support.
- **Options:** Laravel (PHP), NestJS (Node/TS), Django (Python), Rails (Ruby).
- **Decision (proposed):** **Laravel** for velocity, ecosystem, and SaaS/tenancy
  maturity.
- **Consequences:** PHP toolchain; strong batteries-included features; team must
  know Laravel conventions.
- **Approved by:** _pending_

## ADR-002: Multi-tenancy strategy — shared DB + `tenant_id` + RLS
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Must scale to thousands of tenants / hundreds of thousands of
  employees with complete isolation at reasonable ops cost.
- **Options:** (A) shared DB/schema + `tenant_id`; (B) schema-per-tenant;
  (C) database-per-tenant.
- **Decision (proposed):** **Pattern A** (shared DB, shared schema, mandatory
  `tenant_id`) enforced by a global query scope **plus PostgreSQL RLS**; reserve
  **Pattern C** for exceptional enterprise/regulated tenants later.
- **Consequences:** Lowest ops cost, highest scale; isolation must be rigorously
  enforced and tested at every layer.
- **Approved by:** _pending_

## ADR-003: Primary database — PostgreSQL
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Need robust relational DB with RLS, JSONB, partitioning, strong
  indexing for tenancy and high-volume attendance/audit data.
- **Options:** PostgreSQL, MySQL/MariaDB.
- **Decision (proposed):** **PostgreSQL** (RLS + partitioning support are
  decisive).
- **Consequences:** Postgres-specific features (RLS, partitioning) become part
  of the design.
- **Approved by:** _pending_

## ADR-004: API-first + SPA frontend (React/TypeScript)
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Three portals plus a **future mobile API** argue for an
  API-first backend consumed by SPA clients.
- **Options:** Server-rendered; SPA + JSON API; hybrid.
- **Decision (proposed):** **API-first (versioned REST/JSON)** with a **React
  (TS)** SPA; RTL/LTR via an i18n layer.
- **Consequences:** API contracts and versioning discipline; reusable API for
  mobile.
- **Approved by:** _pending_

## ADR-005: Cache/queue — Redis; async workers
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Heavy work (payroll, reports, AI, notifications, geofence checks)
  must run off the request path.
- **Decision (proposed):** **Redis** for cache/sessions/queues; queue workers
  for async jobs.
- **Consequences:** Additional infra component; scalable background processing.
- **Approved by:** _pending_

## ADR-006: Object storage — S3-compatible
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Store payslips, invoices, exports, proof-of-payment, attachments.
- **Decision (proposed):** **S3-compatible** storage with private access +
  signed URLs.
- **Approved by:** _pending_

## ADR-007: Modular monolith to start
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Balance delivery speed with future scale.
- **Decision (proposed):** Build a **modular monolith** with clear module
  boundaries; extract heavy/independent concerns (AI, reporting, payroll
  workers) into services as load demands.
- **Approved by:** _pending_

## ADR-008: Primary key type — UUID/ULID for tenant-scoped entities
- **Status:** Proposed
- **Date:** 2026-08-31
- **Context:** Avoid enumeration, ease future sharding/isolation.
- **Decision (proposed):** UUID or ULID for tenant-scoped entities (final choice
  pending).
- **Approved by:** _pending_

---

## Decisions Requiring Owner Approval (consolidated)

The following need the owner's sign-off before/at the start of implementation:

1. **Technical stack** (ADR-001, 003, 004, 005, 006): Laravel + PostgreSQL +
   Redis + React (TS) + S3-compatible storage.
2. **Multi-tenancy strategy** (ADR-002): shared DB + `tenant_id` + RLS, with
   dedicated-DB option reserved for enterprise.
3. **Architecture style** (ADR-007): modular monolith, API-first.
4. **Primary key strategy** (ADR-008): UUID vs ULID.
5. **Payment gateway provider(s)** for Visa/Mastercard and supported
   regions/currencies (`SAAS-BILLING.md`).
6. **AI provider(s)** and data-handling/training terms and data residency
   (`AI-FEATURES.md`).
7. **Target regions & compliance** posture (GDPR-like rights, data residency,
   certifications) (`SECURITY.md`).
8. **Payroll rulesets**: regional tax/contribution rules, rounding, multi-currency
   payroll (`PAYROLL.md`).
9. **Attendance policy defaults**: overtime rules, kiosk/biometric device support
   (`ATTENDANCE.md`).
10. **Manager permission scoping**: team-based vs branch/department scoping in v1
    (`PERMISSIONS.md`).
11. **Tasks scope**: sub-tasks/dependencies/Kanban in v1 or later (`TASKS.md`).
12. **Sprint roadmap & Sprint 0 scope** as proposed in `SPRINTS.md`.

> Once approved, update the relevant ADR statuses to **Accepted** with the
> approver and date.
