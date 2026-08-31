# Architecture — Raqmi Dawam

**Status:** **Approved** (2026-08-31). The decisions below are owner-approved and
recorded as **Accepted** ADRs in [`DECISIONS.md`](DECISIONS.md) (ADR-001…018).
No framework is installed yet — Sprint 0 implementation has not started.

---

## 1. Architectural Goals

1. **Complete tenant isolation** — the product's most important property.
2. **Scale** to thousands of companies and hundreds of thousands of employees.
3. **Bilingual RTL/LTR** as a first-class concern.
4. **Security & auditability** in every module.
5. **Operational simplicity** early, with a clear path to scale later.
6. **Testability** — isolation, permissions, attendance, payroll, billing all
   provable by automated tests.

## 2. Style Options Compared

### 2.1 Monolith vs. Modular Monolith vs. Microservices

| Option | Pros | Cons | Fit |
|---|---|---|---|
| **Single monolith** | Fastest to build, simple ops, one deploy | Can rot into a big ball of mud; scaling coarse | Good early, risky long-term |
| **Modular monolith** | Clear module boundaries, one deploy, easy transactions, low ops overhead, can extract services later | Requires discipline on boundaries | **Best for this stage** |
| **Microservices** | Independent scaling/teams | High ops complexity, distributed data & tenancy harder, premature for a new product | Not now |

**APPROVED (ADR-007):** Build as a **modular monolith** with strong module
boundaries (Tenancy, Auth, Organization, Attendance, Leave, Tasks, Payroll,
Billing, Notifications, Audit, AI). **Microservices are explicitly out of scope
at this stage.** Heavy or independently-scaling concerns (AI processing,
reporting, payroll runs) may be extracted into asynchronous workers/services
later, only if load demands. This gives fast delivery now and a clean seam for
scale later.

### 2.2 Rendering / Frontend Approach

| Option | Pros | Cons |
|---|---|---|
| Server-rendered + light JS | Simple, SEO-friendly, fast to ship | Richer interactions harder |
| SPA (React/Vue) + JSON API | Rich UX, reusable API for future mobile | More moving parts, needs API from day one |
| Hybrid (server views for admin + API for app/mobile) | Pragmatic | Two surfaces to maintain |

**APPROVED (ADR-004):** Build an **API-first backend** (JSON/REST, versioned)
with a **React + TypeScript SPA** web client. An API-first design directly serves
the required **future mobile application API** without rework, and cleanly
separates the three portals (Super Admin, Company Admin, Employee) as clients of
the same API. RTL/LTR is handled in the client with an i18n framework and logical
CSS properties.

## 3. Approved Technical Stack

> **Approved (ADR-001, 003, 004, 005, 006).** Nothing is installed yet;
> installation happens in Sprint 0 once implementation is authorized.

| Layer | Choice | Rationale | Alternatives considered |
|---|---|---|---|
| **Backend framework** | **Laravel (PHP)** | Mature, batteries-included (auth, queues, policies, migrations, testing), strong ecosystem for SaaS multi-tenancy, excellent for the described feature set and team velocity | NestJS (Node/TS), Django (Python), Ruby on Rails |
| **API style** | **REST + JSON, versioned (`/api/v1`)**, OpenAPI documented | Simple, ubiquitous, mobile-friendly | GraphQL |
| **Web frontend** | **React (TypeScript)** SPA, with an i18n lib (RTL/LTR) | Large ecosystem, strong tooling, first-class RTL support patterns | Vue 3, Inertia + server views |
| **Primary database** | **PostgreSQL** | Robust, scalable, strong indexing, JSONB, and row-level security (RLS) that underpins tenancy | MySQL/MariaDB |
| **Cache / queue broker** | **Redis** | Sessions, cache, rate limiting, queue backend | Memcached (cache only) |
| **Async processing** | **Queue workers** (Laravel Queues on Redis) | Payroll runs, reports, AI, notifications, geofence checks off the request path | — |
| **Object storage** | **S3-compatible** | Payslips, exports, attachments, proof-of-payment | — |
| **Search/reporting (later)** | Read replicas / OLAP store when needed | Keep heavy analytics off the transactional DB | ClickHouse, data warehouse |
| **AI** | Pluggable provider via an **AI Provider abstraction** (ADR-011) | Avoid lock-in; keep tenant data controls | (provider TBD) |
| **Payments** | **Payment Gateway abstraction** (ADR-010) | Card/bank/manual + future regional gateways, provider-agnostic | (provider TBD) |
| **Auth** | First-party auth + JWT/opaque tokens for API; MFA-capable | Standard, mobile-ready | OAuth provider |
| **Infra** | Containerized, horizontally scalable stateless app tier behind a load balancer | Scale-out; 12-factor | Managed PaaS |

**Approved default:** **Laravel + PostgreSQL + Redis + React (TS)**, API-first,
containerized. This maximizes delivery speed for the described scope while
keeping a credible path to hundreds of thousands of employees.

> Note: Nothing is installed in the repository yet. The stack above is approved
> but is only installed when Sprint 0 implementation is explicitly authorized.

## 4. Multi-Tenancy Strategy

Three canonical patterns:

| Pattern | Isolation | Ops cost | Scale ceiling | Notes |
|---|---|---|---|---|
| **A. Shared DB, shared schema, `tenant_id` column** | Logical (enforced in app) | Low | Very high | Cheapest, most scalable; isolation must be enforced rigorously |
| **B. Shared DB, schema-per-tenant** | Stronger | Medium | Medium (schema count limits) | Harder migrations at scale |
| **C. Database-per-tenant** | Strongest | High | Lower (many DBs to run) | Best for a few large/regulated tenants |

**APPROVED (ADR-002): Pattern A — a single shared PostgreSQL database with a
shared schema.** **Every tenant-owned record must include `tenant_id`.**
Isolation is enforced through four layers: **(1) a Laravel tenant context,
(2) global query scopes applied automatically to all queries, (3) PostgreSQL
Row-Level Security (RLS) as a defense-in-depth backstop, and (4) automated
cross-tenant isolation tests.** **Cross-tenant data access is treated as a
critical security vulnerability.** Dedicated database-per-tenant (Pattern C) is
reserved as a future option for exceptional enterprise/regulated tenants only.

Why: Pattern A scales to thousands of tenants and hundreds of thousands of
employees at the lowest operational cost, while the four-layer enforcement
provides strong, provable isolation. See [`DATABASE.md`](DATABASE.md) for the
data-model details and [`SECURITY.md`](SECURITY.md) for enforcement.

### Tenant resolution
- Resolve the tenant from a **subdomain** (`company.raqmidawam.com`) and/or a
  verified auth context/claim.
- The Super Admin portal runs in a **central/landlord** context that can operate
  across tenants under audit.

### Isolation enforcement (layers)
1. **Application:** global query scope injects `tenant_id`; models reject
   cross-tenant writes.
2. **Database:** RLS policies keyed on the current tenant setting.
3. **Testing:** mandatory isolation tests (see rule 3, 4, 7 in `CLAUDE.md`).
4. **Auditing:** cross-context (Super Admin) access is logged.

## 5. Database Strategy (summary)

- **PostgreSQL** as the system of record; **shared schema + `tenant_id`** with
  **RLS**.
- Every tenant-owned table carries `tenant_id` (indexed, part of composite
  indexes/uniqueness).
- **Primary keys are ULID** for application entities (ADR-008).
- **Migrations** are the only way schema changes ship; destructive changes need
  approval (`CLAUDE.md` rule 2).
- **No table partitioning in the initial system (ADR-009).** Indexes are
  designed so partitioning can be added later without a data-model rewrite.
  Read replicas, a dedicated analytics/OLAP store, and partitioning of large
  event tables (attendance, audit) are documented as **future scaling
  strategies only**.
- Backups, point-in-time recovery, and tested restore procedures are required
  before production. Full detail in [`DATABASE.md`](DATABASE.md).

## 6. Application Modules (bounded contexts)

```
+-----------------------------------------------------------+
|                     API Gateway / HTTP                    |
+-----------------------------------------------------------+
| Tenancy | Auth | Organization | Attendance | Leave |Tasks |
|-----------------------------------------------------------|
| Payroll | Billing | Notifications | Audit | Reporting | AI |
+-----------------------------------------------------------+
|      Shared: RBAC · i18n · Storage · Queue · Events        |
+-----------------------------------------------------------+
|                 PostgreSQL · Redis · S3                    |
+-----------------------------------------------------------+
```

Each module owns its domain logic and exposes services/policies to others via
well-defined interfaces and events, keeping boundaries clean for later
extraction.

## 7. Cross-Cutting Concerns

- **RBAC with scopes (ADR-015):** central permission layer, checked in every
  module, supporting **Company / Branch / Department-Team scopes** so a manager
  only reaches resources within their assigned scope (`PERMISSIONS.md`).
- **i18n (ADR-012):** translation resources, RTL/LTR aware; Arabic + English
  mandatory from day one; no hard-coded strings.
- **Payment Gateway abstraction (ADR-010):** provider-agnostic billing driver
  (card/bank/manual + future regional) — `SAAS-BILLING.md`.
- **AI Provider abstraction (ADR-011):** business logic never depends on one AI
  provider; AI performs no autonomous sensitive/destructive actions —
  `AI-FEATURES.md`.
- **Payroll Core (ADR-014):** generic engine; country rules are pluggable
  providers — `PAYROLL.md`.
- **Compliance (ADR-013):** GDPR-ready seams (export, deletion, retention,
  consent, audit, sensitive-data protection) — `SECURITY.md`.
- **Audit:** an audit service subscribes to domain events (`SECURITY.md`).
- **Async & events:** domain events drive notifications, audit, and AI jobs.
- **Observability:** structured logs, metrics, tracing, health checks.
- **Config & secrets:** environment-based; secret manager; nothing in VCS.

## 8. Scalability Path (future strategies — not built now)

Per **ADR-018**, prefer a simple, scalable architecture first and defer the
items below until real load justifies them (**ADR-009** keeps partitioning out
of the initial system):

1. Stateless app tier → scale horizontally behind a load balancer. *(from day
   one)*
2. Redis for cache/queues; workers scale independently. *(from day one)*
3. PostgreSQL: connection pooling *(early)*; **later:** read replicas and table
   partitioning for attendance/audit — RLS keeps isolation intact.
4. **Later:** extract AI, reporting, and payroll workers into dedicated services
   when they dominate load.
5. **Later:** introduce a dedicated analytics/OLAP store for heavy reporting.
6. **Later:** optional dedicated databases for the largest enterprise tenants.

## 9. Decision Status

The stack, tenancy pattern, database strategy, and cross-cutting decisions above
are **owner-approved** and recorded as **Accepted** ADRs (ADR-001…018) in
[`DECISIONS.md`](DECISIONS.md). Remaining unresolved (provider-level) decisions
are listed there and do not block Sprint 0.
