# Architecture — Raqmi Dawam

**Status:** Draft recommendation (planning phase). No framework installed yet.
Choices marked **RECOMMENDED** require owner approval before implementation
(recorded in [`DECISIONS.md`](DECISIONS.md)).

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

**RECOMMENDED:** Start as a **modular monolith** with strong module boundaries
(Tenancy, Auth, Organization, Attendance, Leave, Tasks, Payroll, Billing,
Notifications, Audit, AI). Extract heavy or independently-scaling concerns
(AI processing, reporting, payroll runs) into asynchronous workers/services as
load demands. This gives fast delivery now and a clean seam for scale later.

### 2.2 Rendering / Frontend Approach

| Option | Pros | Cons |
|---|---|---|
| Server-rendered + light JS | Simple, SEO-friendly, fast to ship | Richer interactions harder |
| SPA (React/Vue) + JSON API | Rich UX, reusable API for future mobile | More moving parts, needs API from day one |
| Hybrid (server views for admin + API for app/mobile) | Pragmatic | Two surfaces to maintain |

**RECOMMENDED:** Build an **API-first backend** (JSON/REST, versioned) with a
**SPA web client**. An API-first design directly serves the required **future
mobile application API** without rework, and cleanly separates the three portals
(Super Admin, Company Admin, Employee) as clients of the same API. RTL/LTR is
handled in the client with an i18n framework and logical CSS properties.

## 3. Recommended Technical Stack

> These are recommendations for owner approval; nothing is installed yet.

| Layer | Recommendation | Rationale | Alternatives |
|---|---|---|---|
| **Backend framework** | **Laravel (PHP)** | Mature, batteries-included (auth, queues, policies, migrations, testing), strong ecosystem for SaaS multi-tenancy (e.g. tenancy packages), excellent for the described feature set and team velocity | NestJS (Node/TS), Django (Python), Ruby on Rails |
| **API style** | **REST + JSON, versioned (`/api/v1`)**, OpenAPI documented | Simple, ubiquitous, mobile-friendly | GraphQL |
| **Web frontend** | **React (TypeScript)** SPA, with an i18n lib (RTL/LTR) | Large ecosystem, strong tooling, first-class RTL support patterns | Vue 3, Inertia + server views |
| **Primary database** | **PostgreSQL** | Robust, scalable, strong indexing, JSONB, mature partitioning & row-level security options that support tenancy | MySQL/MariaDB |
| **Cache / queue broker** | **Redis** | Sessions, cache, rate limiting, queue backend | Memcached (cache only) |
| **Async processing** | **Queue workers** (Laravel Queues on Redis) | Payroll runs, reports, AI, notifications, geofence checks off the request path | — |
| **Object storage** | **S3-compatible** | Payslips, exports, attachments, proof-of-payment | — |
| **Search/reporting (later)** | Read replicas / OLAP store when needed | Keep heavy analytics off the transactional DB | ClickHouse, data warehouse |
| **AI** | Pluggable provider via an abstraction layer | Avoid lock-in; keep tenant data controls | (provider TBD) |
| **Auth** | First-party auth + JWT/opaque tokens for API; MFA-capable | Standard, mobile-ready | OAuth provider |
| **Infra** | Containerized, horizontally scalable stateless app tier behind a load balancer | Scale-out; 12-factor | Managed PaaS |

**RECOMMENDED default:** **Laravel + PostgreSQL + Redis + React (TS)**, API-first,
containerized. This maximizes delivery speed for the described scope while
keeping a credible path to hundreds of thousands of employees.

> Note: The initial task explicitly forbids installing Laravel/React/Vue/Node
> packages. The stack above is a **proposal** only.

## 4. Multi-Tenancy Strategy

Three canonical patterns:

| Pattern | Isolation | Ops cost | Scale ceiling | Notes |
|---|---|---|---|---|
| **A. Shared DB, shared schema, `tenant_id` column** | Logical (enforced in app) | Low | Very high | Cheapest, most scalable; isolation must be enforced rigorously |
| **B. Shared DB, schema-per-tenant** | Stronger | Medium | Medium (schema count limits) | Harder migrations at scale |
| **C. Database-per-tenant** | Strongest | High | Lower (many DBs to run) | Best for a few large/regulated tenants |

**RECOMMENDED:** **Hybrid, defaulting to Pattern A (shared database, shared
schema with a mandatory `tenant_id` on every tenant-owned table)** enforced by a
**global tenant scope** applied automatically to all queries, plus PostgreSQL
**Row-Level Security (RLS)** as a defense-in-depth backstop. Reserve **Pattern C
(dedicated database)** as an option for exceptional enterprise/regulated tenants
later.

Why: Pattern A scales to thousands of tenants and hundreds of thousands of
employees at the lowest operational cost, while a **framework-level global scope
+ RLS + tests** provides strong, provable isolation. See
[`DATABASE.md`](DATABASE.md) for the data-model details and
[`SECURITY.md`](SECURITY.md) for enforcement.

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
- **Migrations** are the only way schema changes ship; destructive changes need
  approval (`CLAUDE.md` rule 2).
- Heavy read/analytics workloads move to **read replicas** and, later, a
  dedicated analytics store; large event tables (attendance, audit) use
  **partitioning** (e.g., by month) as volume grows.
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

- **RBAC:** central permission layer, checked in every module (`PERMISSIONS.md`).
- **i18n:** translation resources, RTL/LTR aware; no hard-coded strings.
- **Audit:** an audit service subscribes to domain events (`SECURITY.md`).
- **Async & events:** domain events drive notifications, audit, and AI jobs.
- **Observability:** structured logs, metrics, tracing, health checks.
- **Config & secrets:** environment-based; secret manager; nothing in VCS.

## 8. Scalability Path

1. Stateless app tier → scale horizontally behind a load balancer.
2. Redis for cache/queues; workers scale independently.
3. PostgreSQL: connection pooling, read replicas, table partitioning for
   attendance/audit; RLS keeps isolation intact.
4. Extract AI, reporting, and payroll workers into dedicated services when they
   dominate load.
5. Introduce a dedicated analytics/OLAP store for heavy reporting.
6. Optional dedicated databases for the largest enterprise tenants.

## 9. Decisions Needing Approval

The stack, tenancy pattern, and database strategy above are **proposals**.
See [`DECISIONS.md`](DECISIONS.md) for the list of decisions requiring owner
sign-off.
