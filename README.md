# Raqmi Dawam — رقمي دوام

**Raqmi Dawam** is a commercial, multi-tenant **SaaS workforce management
platform** for companies and institutions. It brings company onboarding,
subscriptions and billing, employee and organizational management, attendance
(including GPS and geofencing), leave, tasks, payroll, reporting, notifications,
audit logging, and an AI assistant into one bilingual (**Arabic RTL** /
**English LTR**) product.

> **Project status: Planning & documentation phase.**
> No application framework, package, or database migration has been created yet.
> This repository currently contains **only** the planning and documentation
> foundation. See [`CLAUDE.md`](CLAUDE.md) for the binding development rules.

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

## Contributing

All contributors must follow [`CLAUDE.md`](CLAUDE.md). Key rules: complete tenant
isolation, no cross-tenant access, permission-gated sensitive data, audit
logging of important actions, automated tests for major features, maintained
bilingual RTL/LTR support, no hard-coded translations, and no secrets in version
control.

## License

Proprietary — © Raqmi Dawam. All rights reserved. Not for redistribution.
