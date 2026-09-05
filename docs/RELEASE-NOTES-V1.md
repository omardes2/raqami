# Release Notes — Raqmi Dawam (رقمي دوام) V1

**Raqmi Dawam** is a commercial, multi-tenant SaaS workforce-management platform.
V1 delivers the full company lifecycle — onboarding, subscriptions, employees,
attendance, leave, tasks, payroll, reporting, notifications, an assistive AI
layer, and a mobile API — bilingual in **Arabic (RTL)** and **English (LTR)**,
with tenant isolation enforced at both the application layer and PostgreSQL
Row-Level Security.

---

## Foundations

- **Multi-tenancy with defense in depth.** Every tenant table is isolated by a
  global application scope *and* FORCE Row-Level Security keyed on a
  per-connection tenant GUC. Cross-tenant reads/writes are impossible by design;
  the only cross-tenant surface is the audited, read-only Super Admin portal.
- **Granular RBAC.** Roles with scoped permissions (company / branch /
  department / team). The backend is always authoritative; the UI only hides
  controls. Sensitive data (salary, national IDs, bank details) is
  permission-gated and never exposed by default.
- **Auditability.** Authentication, permission changes, payroll runs, billing
  events, exports, and destructive actions are recorded with actor, tenant,
  target, and timestamp.
- **Bilingual by contract.** All user-facing text is translated (no hard-coded
  strings); every feature works correctly in both RTL and LTR.

## Company, billing & identity

- Self-service company onboarding (founder becomes Owner) with a trial
  subscription bootstrap.
- Plans, subscriptions, invoices, payments (manual/bank-transfer gateway
  abstraction), and subscription lifecycle transitions (trial/grace expiry,
  scheduled cancel/downgrade).
- Users, invitations, memberships, and roles — with a role-ceiling guard so a
  non-owner can never escalate beyond their own authority.

## Organization & employees

- Branches, departments (with subtree scoping), teams, and job titles.
- Employees with contracts, transfers, history, emergency contacts, and private
  document storage. **User ≠ Employee** — the two are distinct and either can
  exist without the other.

## Attendance

- Check-in/out with GPS and geofencing; work schedules including split shifts
  and rotation patterns; holiday calendars; exceptions/remote/off-day handling.
- Session-aware records, daily materialization, overtime approval, anomaly
  detection, correction workflow with optimistic concurrency, and scoped
  reporting.

## Leave

- Leave types and policies, entitlement periods with carry-forward/expiry,
  ledger balances, requests with approvals, attachments, and reports —
  concurrency- and idempotency-hardened.

## Tasks

- Projects and tasks with a configurable status catalog, assignment,
  visibility resolution, comments, checklists, attachments, and reports.

## Payroll

- Compensation components, payroll periods and runs, calculation engine,
  adjustments, **four-eyes** approval (approve ≠ finalize), and finalization
  producing immutable payslips.
- Financial safety rails: finalized history is immutable (append-only ledgers +
  DB triggers), currencies are never combined, and no non-finalized money
  appears in reports.

## Reporting, dashboard & notifications

- Company dashboard composing only the KPI cards the caller is authorized and
  scoped to see; organization/workforce and payroll reports.
- Personal notification inbox (recipient-scoped RLS), producers for leave,
  tasks, attendance corrections, and payslips, plus scheduled reminders,
  reconciliation, and retention pruning.

## AI assistant (assistive, read-only)

- Neutral AI-provider interface, **disabled by default** (`null` driver); an
  optional server-side Anthropic Claude driver gated by config and a key that is
  never exposed to the frontend.
- Four assistive features (company dashboard summary, attendance/leave insights,
  task workload summary, report explanation), each gated by `ai.use` and the
  relevant report permission, operating only on already-authorized aggregates.
  The AI **never** mutates payroll, attendance, leave, or any sensitive state,
  and receives no unauthorized or private data. A minimal usage ledger records
  provider/model/units/cost per call, per tenant.

## Mobile API

- Versioned `/api/mobile/v1` Bearer-token authentication (Sanctum personal
  access tokens, ADR-004). Mobile clients consume the **same** application API as
  the SPA with identical tenancy, RLS, and permission guarantees — only the
  identity carrier differs. See `docs/MOBILE-API.md`.

## Production hardening (Sprint 10)

- Vertical privilege-escalation guard on role assignment.
- Request-lifetime authorization memoization (removes an attendance-listing
  N+1) with a query-count regression guard.
- Rate limiting on sensitive endpoints (AI, profile update, mobile login).
- Registered production scheduler (single `schedule:run` cron) for all
  idempotent, tenant-aware maintenance commands.
- Operational documentation: deployment runbook, production security checklist,
  and this release note.

---

## Operating requirements

- PHP 8.3+, PostgreSQL (application role **must** be `NOBYPASSRLS`), Redis
  (cache/session/queue), S3-compatible private object storage.
- A supervised queue worker and a single `schedule:run` cron entry are required
  in production. See `docs/DEPLOYMENT.md` and `docs/PRODUCTION-CHECKLIST.md`.

## Known limitations / deferred

- AI features ship disabled by default and are assistive only.
- Payment gateway is an abstraction with a manual/bank-transfer driver; no
  automated card processing in V1.
- Host-based tenant resolution (subdomain/custom domain) is stubbed for a future
  release; tenant selection is via the authenticated user's membership and the
  `X-Tenant-Id` header.
