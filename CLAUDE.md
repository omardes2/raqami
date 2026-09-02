# CLAUDE.md — Development Constitution for Raqmi Dawam (رقمي دوام)

This file defines the **permanent development rules** for the Raqmi Dawam
platform. It is binding on every contributor — human or AI — working in this
repository. These rules take precedence over convenience, speed, or personal
preference. When in doubt, stop and ask the project owner.

> **Status:** Documentation/planning phase. No application framework, package,
> or migration has been installed yet. Do not begin application implementation
> until explicitly instructed.

---

## 1. Product Summary

**Raqmi Dawam** is a commercial, multi-tenant **SaaS workforce management
platform** for companies and institutions. It covers company onboarding,
subscriptions and billing, employees, departments, branches, roles and granular
permissions, attendance (including GPS/geofencing), leave, tasks, payroll,
reporting, notifications, audit logs, an AI assistant, and a future mobile API.
It ships bilingual: **Arabic (RTL)** and **English (LTR)**.

The product is designed to eventually serve **thousands of companies** and
**hundreds of thousands of employees**.

---

## 2. Permanent Development Rules

These fifteen rules are non-negotiable. They may only be changed with the
explicit written approval of the project owner, recorded in
[`docs/DECISIONS.md`](docs/DECISIONS.md).

1. **Never remove or change approved business requirements without explicit
   approval.** Approved requirements are contracts. Propose, get approval,
   record the decision — then change.
2. **Never make destructive database changes without approval.** Dropping
   columns/tables, irreversible data transforms, or destructive migrations
   require explicit owner sign-off and a tested rollback path.
3. **Every tenant/company must have complete data isolation.** Tenancy is the
   foundation of the product. Isolation is enforced by design, not by
   convention.
4. **Cross-tenant data access is strictly prohibited.** No query, endpoint,
   job, report, or AI feature may read or write another tenant's data. The
   only exception is the Super Admin portal, which is audited and explicitly
   scoped.
5. **Payroll and sensitive employee information must be protected by
   permissions.** Salary, national IDs, bank details, and similar data are
   permission-gated and never exposed by default.
6. **Important actions must be recorded in Audit Logs.** Authentication,
   permission changes, payroll runs, subscription/billing events, data
   exports, and destructive actions are logged with actor, tenant, target,
   and timestamp.
7. **Major functionality must have automated tests.** Tenancy isolation,
   permissions, attendance rules, payroll math, and billing must be covered by
   tests before they are considered done.
8. **Arabic RTL and English LTR must be maintained.** Every user-facing feature
   works correctly in both directions. Neither locale is an afterthought.
9. **Never hard-code interface translations.** All UI strings come from
   translation files/keys. No literal user-facing text in code.
10. **Never commit secrets, API keys, passwords, or `.env` values.** Secrets
    live in environment configuration and secret managers only. `.env` is
    git-ignored; only `.env.example` (with placeholder values) is committed.
11. **Work incrementally by Sprint.** Deliver in the sequence defined in
    [`docs/SPRINTS.md`](docs/SPRINTS.md).
12. **Do not implement future Sprints unless explicitly requested.** Stay within
    the current sprint's scope.
13. **Record important product and architecture decisions in
    [`docs/DECISIONS.md`](docs/DECISIONS.md).** Use the ADR format defined
    there.
14. **Security must be considered in every module.** Threat-model each feature.
    See [`docs/SECURITY.md`](docs/SECURITY.md).
15. **Before major architecture changes, explain the proposed change and
    request approval.** Describe the change, its impact, and alternatives, then
    wait for sign-off before implementing.

---

## 3. Working Method

- **Sprint-driven.** The roadmap lives in [`docs/SPRINTS.md`](docs/SPRINTS.md).
  Sprint 0 is the foundation; it is **not** to be implemented until requested.
- **Documentation first.** Significant features are specified in `docs/` before
  code. Keep docs and code in sync.
- **Decisions are logged.** Anything that constrains the future — a library
  choice, a tenancy strategy, a schema convention — becomes an ADR in
  `docs/DECISIONS.md`.
- **Ask when unsure.** For anything touching tenancy, security, payroll, or
  billing, prefer a question over an assumption.

---

## 4. Non-Negotiable Invariants (quick reference)

| Invariant | Enforced by |
|---|---|
| Tenant isolation | Architecture, DB design, query scoping, tests |
| No cross-tenant reads/writes | Global tenant scope + tests + audits |
| Permission-gated sensitive data | RBAC layer + policy checks + tests |
| Auditability of critical actions | Audit log service |
| Bilingual RTL/LTR | i18n layer, no hard-coded strings |
| No secrets in VCS | `.gitignore`, secret manager, CI secret scan |
| Tests for major features | CI gate |

---

## 5. Out of Scope Right Now

Per the current task, **do not**:
- Install Laravel, React, Vue, Node packages, or any framework.
- Implement application functionality.
- Create database migrations.

The current deliverable is **planning and documentation only**.

---

## 6. Document Map

| File | Purpose |
|---|---|
| [`README.md`](README.md) | Project overview and orientation |
| [`docs/PRD.md`](docs/PRD.md) | Product requirements |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Architecture comparison & recommendation |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Conceptual data model |
| [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) | Roles & granular permissions |
| [`docs/ATTENDANCE.md`](docs/ATTENDANCE.md) | Attendance, shifts, GPS, geofencing |
| [`docs/LEAVE.md`](docs/LEAVE.md) | Leave types, policies, ledger balances, approvals |
| [`docs/TASKS.md`](docs/TASKS.md) | Tasks & team management |
| [`docs/PAYROLL.md`](docs/PAYROLL.md) | Payroll & payslips |
| [`docs/SAAS-BILLING.md`](docs/SAAS-BILLING.md) | Plans, subscriptions, payments |
| [`docs/AI-FEATURES.md`](docs/AI-FEATURES.md) | AI assistant, reports, insights |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security model & controls |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Architecture Decision Records |
| [`docs/SPRINTS.md`](docs/SPRINTS.md) | Development roadmap |
