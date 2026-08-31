# Raqmi Dawam — رقمي دوام

**Raqmi Dawam** is a commercial, multi-tenant **SaaS workforce management
platform** for companies and institutions. It brings company onboarding,
subscriptions and billing, employee and organizational management, attendance
(including GPS and geofencing), leave, tasks, payroll, reporting, notifications,
audit logging, and an AI assistant into one bilingual (**Arabic RTL** /
**English LTR**) product.

> **Project status: Sprint 0 (Platform Foundation) implemented.**
> The repository now contains the working platform foundation — a Laravel
> API-first backend (`backend/`) and a React + TypeScript SPA (`frontend/`) —
> alongside the planning docs. Later sprints (Attendance, Tasks, Leave, Payroll,
> Billing integrations, AI, Reports) are **not** implemented. See
> [`CLAUDE.md`](CLAUDE.md) for the binding development rules and
> [`docs/SPRINTS.md`](docs/SPRINTS.md) for the roadmap.

---

## Vision

Give organizations of every size a single, trustworthy system to manage their
workforce — from the moment an employee clocks in, to the day payroll is paid —
while keeping each company's data completely isolated and secure. The platform
is built to scale to **thousands of companies** and **hundreds of thousands of
employees**.

## Product Scope (high level)

- **SaaS & billing:** multi-tenancy, company registration/onboarding,
  subscription plans, Visa/Mastercard, bank transfer, and manual/cash payments.
- **Organization:** companies, branches, departments, employees, teams.
- **Access control:** roles and granular permissions.
- **Attendance:** check-in/out, configurable working days & hours, shifts,
  grace periods, overtime, GPS attendance, geofencing.
- **Leave management:** requests, approvals, balances, policies.
- **Tasks & teams:** task assignment, tracking, team management.
- **Payroll:** payroll runs, payslips, deductions, allowances.
- **Insight:** reports, notifications, audit logs.
- **AI:** assistant, AI reports & insights, AI task generation, AI workload
  analysis.
- **Portals:** Super Admin, Company Admin, Employee — plus a future mobile
  application API.
- **Localization:** Arabic (RTL) and English (LTR) throughout.

## Portals

| Portal | Audience | Purpose |
|---|---|---|
| **Super Admin** | Platform operator | Manage tenants, plans, billing, platform health |
| **Company Admin** | Tenant administrators | Manage their company's org, attendance, payroll, staff |
| **Employee** | Individual employees | Attendance, leave, tasks, payslips, self-service |
| **Mobile API** (future) | Mobile apps | Employee-facing functionality on the go |

## Documentation

Start with [`CLAUDE.md`](CLAUDE.md) (development rules), then:

| Document | What it covers |
|---|---|
| [`docs/PRD.md`](docs/PRD.md) | Product requirements & user stories |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Architecture options & recommendation |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Conceptual database design |
| [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) | RBAC model |
| [`docs/ATTENDANCE.md`](docs/ATTENDANCE.md) | Attendance engine |
| [`docs/TASKS.md`](docs/TASKS.md) | Tasks & teams |
| [`docs/PAYROLL.md`](docs/PAYROLL.md) | Payroll & payslips |
| [`docs/SAAS-BILLING.md`](docs/SAAS-BILLING.md) | Plans, subscriptions & payments |
| [`docs/AI-FEATURES.md`](docs/AI-FEATURES.md) | AI capabilities |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security model |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Decision records (ADRs) |
| [`docs/SPRINTS.md`](docs/SPRINTS.md) | Roadmap & sprints |

## Roadmap at a glance

Development proceeds **sprint by sprint** (see [`docs/SPRINTS.md`](docs/SPRINTS.md)).
**Sprint 0** establishes the platform foundation: application scaffolding, SaaS
multi-tenancy, authentication, companies, users, roles & permissions,
localization, audit logs, the Super Admin foundation, and the automated-testing
foundation. **Sprint 0 has not been implemented yet** and must not be started
until explicitly requested.

## Repository Layout

| Path | What it is |
|---|---|
| `backend/` | Laravel (PHP) API-first backend — modular monolith |
| `frontend/` | React + TypeScript SPA (Vite) |
| `docs/` | Product & architecture documentation |
| `.github/workflows/ci.yml` | CI: backend tests + style, frontend lint + build |

The backend is organized as a **modular monolith** under `backend/app/Modules/`:
`Tenancy`, `Identity`, `Authorization`, `Audit`, `Platform`, `Onboarding`,
`Localization`, `Compliance`, plus `Billing`/`Ai` contract seams. Future business
modules (Attendance, Tasks, Payroll, Billing, AI) will be added as separate
modules and are intentionally absent now.

## Getting Started (local development)

**Prerequisites:** PHP 8.4+, Composer, Node 20+/22, PostgreSQL 16, Redis.

### 1. Database (PostgreSQL) — non-superuser app role

Row-Level Security only protects tenants when the app connects as a
**non-superuser** role (superusers and, without `FORCE`, table owners bypass
RLS). Create a restricted role and databases:

```sql
CREATE ROLE raqmi LOGIN PASSWORD 'change-me' NOSUPERUSER NOCREATEROLE NOBYPASSRLS CREATEDB;
CREATE DATABASE raqmi_dawam       OWNER raqmi;
CREATE DATABASE raqmi_dawam_test  OWNER raqmi;
```

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env          # then set DB_PASSWORD etc. (never commit .env)
php artisan key:generate
php artisan migrate --seed    # creates schema + RLS; seeds permissions + a dev Super Admin
php artisan serve             # http://localhost:8000
```

Redis powers cache/session/queue in local/dev (`.env` defaults). Queue workers:
`php artisan queue:work`.

### 3. Frontend

```bash
cd frontend
npm install
npm run dev                   # http://localhost:5173 (proxies /api to :8000)
```

Open `http://localhost:5173`, register an account, create your company, and you
are in. The Super Admin portal is at `/platform/login` (seeded dev credentials
come from `PLATFORM_ADMIN_EMAIL` / `PLATFORM_ADMIN_PASSWORD`).

## Testing

Backend tests run against PostgreSQL as the non-superuser role so tenant
isolation and RLS are exercised for real:

```bash
cd backend
php artisan test          # tenant isolation (app + RLS), auth, authorization,
                          # platform separation, localization, audit
vendor/bin/pint --test    # code style
```

Frontend:

```bash
cd frontend
npm run lint
npm run build             # type-check + production build
```

CI runs all of the above on every push (`.github/workflows/ci.yml`) using an
ephemeral database — **no production secrets required**.

## Contributing

All contributors must follow [`CLAUDE.md`](CLAUDE.md). Key rules: complete tenant
isolation, no cross-tenant access, permission-gated sensitive data, audit
logging of important actions, automated tests for major features, maintained
bilingual RTL/LTR support, no hard-coded translations, and no secrets in version
control.

## Branching & Git Workflow

`main` is the stable production/integration baseline. **No sprint development
happens directly on `main`.** Each sprint (and any change) is built on a feature
branch and merged into `main` through a reviewed, CI-passing Pull Request.

```
main
 └── feature/sprint-X-...        # e.g. feature/sprint-1-organization-employees
      → implement
      → run local tests (backend: php artisan test + pint; frontend: lint + build)
      → push
      → GitHub CI runs (Backend CI + Frontend CI)
      → open Pull Request into main
      → review
      → merge (only when CI is green)
```

Rules:

- Branch off `main`: `git checkout main && git pull && git checkout -b feature/sprint-1-organization-employees`.
- Keep feature branches focused on a single sprint/change.
- Every PR into `main` must have **green CI** (Backend + Frontend jobs) before merge.
- Never force-push or delete `main`; never rewrite `main`'s history.

> **Repository settings (owner action required).** Making `main` the default
> branch and enforcing the rules above (required CI checks, required PR, no
> force-push, no deletion) are GitHub **repository settings** that must be
> applied by the repository owner in the GitHub UI/API — they cannot be set
> from the CI/automation environment. See the stabilization report / project
> owner notes for the exact steps.

## License

Proprietary — © Raqmi Dawam. All rights reserved. Not for redistribution.
