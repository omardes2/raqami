# Security Model — Raqmi Dawam

**Status:** Design (planning phase). Security is a requirement in **every**
module (`CLAUDE.md` rule 14). This document defines the platform's security
principles, tenant-isolation model, data protection, audit logging, and secure
development practices.

---

## 1. Principles

- **Isolation first:** complete tenant data isolation; no cross-tenant access.
- **Least privilege:** users and services get only what they need.
- **Defense in depth:** multiple layers enforce each guarantee.
- **Secure by default:** deny by default; sensitive data hidden unless
  permitted.
- **Auditable:** important actions are recorded immutably.
- **No secrets in code:** secrets live in environment/secret managers only.

## 2. Tenant Isolation (the core guarantee)

Enforced in layers (see `ARCHITECTURE.md`):
1. **Application:** a mandatory **global tenant scope** injects `tenant_id` into
   every tenant-owned query; models reject writes with a mismatched tenant.
2. **Database:** PostgreSQL **Row-Level Security (RLS)** policies keyed on the
   active tenant, as a backstop even if app code errs.
3. **Request context:** tenant resolved from subdomain and/or verified auth
   claim; the active tenant is set per request and cannot be spoofed by input.
4. **Super Admin exception:** the only cross-tenant context, explicitly scoped
   and **fully audited**.
5. **Tests:** mandatory isolation tests prove no role/endpoint/job/report/AI
   feature can reach another tenant's data (`CLAUDE.md` rules 3–4, 7).

## 3. Authentication

- Strong password hashing (e.g., Argon2/bcrypt); password policies.
- **MFA** capability for privileged roles (Owner, Admin, Super Admin).
- Session management with secure, httpOnly, sameSite cookies (web) and
  short-lived tokens + refresh for API/mobile.
- Brute-force protection (rate limiting, lockout), and login/audit events.
- Email verification for registration; secure password reset flows.

## 4. Authorization

- Central **RBAC** with granular permissions (`PERMISSIONS.md`).
- Policy checks in every module before read/write.
- Sensitive fields (payroll, national ID, bank details) stripped unless the
  caller holds the required permission.
- Separation of duties for high-risk actions (e.g., payroll run vs approve).

## 5. Data Protection

- **In transit:** TLS everywhere.
- **At rest:** encryption for sensitive fields (national ID, bank account,
  compensation) and for backups.
- **Tokenization:** card data handled by a PCI-compliant gateway; the platform
  stores only tokens/references (`SAAS-BILLING.md`).
- **PII/GPS:** treated as personal data with permission-gated access and
  retention limits (`ATTENDANCE.md`).
- **Least data:** collect and expose the minimum necessary.

## 6. Audit Logging

- **Append-only** `audit_logs` (`DATABASE.md`); no updates/deletes.
- Records: actor (user/system/super_admin), tenant, action, entity, redacted
  before/after, IP, user agent, timestamp.
- **Logged events include (at minimum):**
  - Authentication (login success/failure, logout, MFA changes).
  - Permission/role changes and grants.
  - Access/changes to sensitive data (payroll, employee sensitive fields).
  - Payroll run/adjust/approve/mark-paid.
  - Billing/subscription/payment events (including manual/bank records).
  - Attendance adjustments, schedule/shift/geofence changes.
  - Data exports.
  - Super Admin cross-tenant access.
  - Destructive actions.
- **No secrets** in audit payloads (redact before/after).

## 7. Secrets Management

- `.env` is git-ignored; only `.env.example` with placeholders is committed
  (`CLAUDE.md` rule 10).
- Secrets injected via environment/secret manager in each deployment.
- **CI secret scanning** to catch accidental commits.
- Rotate credentials; never log secrets.

## 8. Application Security Practices

- Input validation and output encoding; parameterized queries (no raw string
  SQL with user input).
- Protection against OWASP Top 10 (XSS, CSRF, SQLi, SSRF, IDOR, etc.).
- IDOR specifically mitigated by tenant scope + object-level authorization.
- Rate limiting and abuse protection on public and auth endpoints.
- Secure file uploads (type/size checks, malware scanning where applicable,
  private storage with signed URLs).
- Webhook verification (signatures) and idempotency for billing/AI callbacks.
- Dependency and container image scanning in CI.
- Security headers (CSP, HSTS, etc.) on web surfaces.

## 9. Localization & Security

- No hard-coded translations (`CLAUDE.md` rule 9); user-facing security messages
  are localized without leaking sensitive detail.

## 10. Operational Security

- Backups with tested restore; point-in-time recovery.
- Monitoring/alerting on anomalies (auth failures, isolation checks).
- Incident response plan (to be authored before production).
- Data retention and deletion policy per tenant and per data class.

## 11. Compliance (direction)

- Design toward regional data-protection expectations (e.g., GDPR-like data
  subject rights, data residency options). Specific certifications/regions are
  an open decision (`DECISIONS.md`).

## 12. Testing (mandatory)

Per `CLAUDE.md` rule 7, security-critical behavior must be covered by automated
tests, especially:
- Tenant isolation across all layers.
- Permission enforcement and sensitive-field protection.
- Auth flows (MFA, lockout, reset).
- Audit completeness for critical actions.

## 13. Open Questions (see `DECISIONS.md`)

- Target regions and compliance certifications.
- MFA method(s) and enforcement policy.
- Data residency for tenants and AI processing.

---

## 14. Sprint 1 additions (Organization & Employees)

- **RLS extended** to all new tenant-owned tables (branches, job_titles,
  departments, employees, teams, team_memberships, employee_emergency_contacts,
  employee_documents, employee_contracts, employee_history_events) with the same
  `tenant_isolation` + `platform_readonly` policies (ENABLE + FORCE).
- **IDOR prevention:** route-model binding runs *after* tenant context is
  established (middleware priority), and `EmployeeScopeResolver` enforces
  organizational scope at row and query level. Out-of-scope / cross-tenant
  access returns a scope-safe 404. Covered by tests.
- **Sensitive employee data** (personal email/phone, DOB, nationality, address,
  notes, emergency contacts) is gated by `employees.view_sensitive` and never
  serialized in list endpoints.
- **Employee documents** live on a private, S3-compatible disk; only
  `storage_key`s are stored (hidden from serialization), downloads are
  authorized + streamed (no public URLs; S3 uses short-lived signed URLs),
  size/type validated, and upload/download/delete are audited.
- **Employee/User separation:** linking is validated (user must be a tenant
  member; no cross-tenant users; one active employee per user) and audited.
- **Mass assignment:** all writes use validated FormRequest data or whitelists;
  `tenant_id` is stamped by the tenant context, never client-supplied.
- **Two logs, two purposes:** the immutable `audit_logs` records security
  events; `employee_history_events` is the HR business timeline. Neither stores
  secrets or document contents.

---

## 15. Sprint 2 additions (SaaS Billing & Subscriptions)

- **RLS extended** to all tenant-linked billing tables (subscriptions,
  subscription_changes, subscription_events, billing_profiles, invoices,
  invoice_items, payments, bank_transfer_submissions, coupon_redemptions,
  billing_counters) with the same `tenant_isolation` + `platform_readonly`
  (ENABLE + FORCE) policies. Platform-global tables (plans, plan_features,
  coupons, bank_accounts) and infra tables (payment_webhook_events,
  idempotency_records) are intentionally not tenant-scoped.
- **Server-authoritative money:** all invoice/payment totals are computed
  server-side from line items; the client never supplies totals. Overpayment is
  rejected (no account credits); payment currency must match invoice currency.
- **Separation of duties:** a tenant user can never approve their own
  bank-transfer or record a succeeded payment — approval/manual recording is
  platform-admin-only. Writes still run under the target tenant's RLS context.
- **Idempotency / no double-charge:** invoice row-lock + status guard on
  bank-transfer approval and payment application; `idempotency_records` and a
  unique `payments.idempotency_key` back the webhook seam. No provider SDK, no
  public webhook endpoint, no card data stored.
- **Private receipts:** bank-transfer proofs live on a private disk
  (`proof_storage_key` hidden from serialization); downloads are authorized +
  streamed. Bank-account `internal_notes` are platform-only.
- **Mass assignment / IDOR:** all writes use validated FormRequests; `tenant_id`
  is stamped by the tenant context; cross-tenant billing access returns a
  scope-safe 404 and cross-tenant writes are blocked by the tenant guard.

## 16. Sprint 2 — commercial hardening (security-relevant)

- **Fail-closed entitlements:** product features require a usable subscription;
  "no subscription" never means unlimited. Billing/recovery routes stay reachable.
- **No unpaid entitlement:** plan upgrades and reactivations apply only after the
  linked invoice is fully paid (payment-gated), enforced server-side.
- **Employee-limit race closed:** the check + insert share one transaction under a
  per-tenant advisory lock.
- **Platform read-only cannot write:** verified by test — under the audited
  read-only context, RLS permits SELECT only; writes affect zero tenant rows.
- **Raw-RLS coverage** now asserts isolation on every tenant-linked billing table
  (subscriptions, subscription_changes, subscription_events, billing_profiles,
  invoices, invoice_items, payments, bank_transfer_submissions, coupon_redemptions).
- **Invoice numbers** are globally unique (unambiguous for reconciliation/audit).
- **Client-safe 422s** for invalid commercial transitions (no internal 500s).

## 17. Sprint 3 additions (Attendance Core)

- **Server decides, client only supplies facts.** The client sends coordinates
  and punch intent; the SERVER sets the instant (its own clock), resolves the
  schedule, computes the Haversine geofence decision, and derives lateness,
  worked time, and status. No client-supplied "I am inside" / "I arrived at
  08:00" / "I worked 8 hours" is ever trusted.
- **Sensitive GPS.** Precise coordinates are permission-gated
  (`attendance.view_location`, scope-aware per employee — NB-1) or visible only
  to the employee on their own record; other viewers see only the derived
  inside/outside flag. GPS is treated as personal data (§5).
- **FORCE RLS on all attendance tables.** Raw-SQL tests assert cross-tenant
  isolation for `attendance_records`/`attendance_events`; platform read-only
  context can SELECT but never write attendance.
- **Concurrency & idempotency.** Check-in/out run in one transaction under a
  per-employee advisory xact lock + row locks; a partial unique index enforces
  one open record per employee; `client_request_id` (partial unique index)
  makes retries idempotent — no double punches under races or replays.
- **Eligibility & self-service scope.** Only active/onboarding/probation
  employees may punch; self-service requires an authenticated, employee-linked
  user (never acts on another employee's record). Admin operations are org-scope
  constrained via `EmployeeScopeResolver` (scope-safe 404, no existence leak).
- **Segregation of duties.** Attendance corrections require a reviewer different
  from the requester (no self-approval); manual entry and every approve/reject is
  audited with actor, tenant, target, and timestamp.
- **Client-safe 422s** for ineligible/duplicate/out-of-geofence/window-violation
  punches (no internal 500s).

### Sprint 4 — Attendance Advanced

- **FORCE RLS on every new table.** All eight new Sprint 4 tenant tables
  (`attendance_sessions`, `work_schedule_segments`, `holiday_calendars`,
  `holidays`, `holiday_calendar_assignments`, `attendance_exceptions`,
  `overtime_approvals`, `attendance_anomalies`) carry `tenant_id` + FORCE RLS;
  raw-SQL cross-tenant tests prove isolation, and the platform read-only context
  can SELECT but never write them.
- **Session integrity.** At most one open session per employee (partial unique
  index + advisory lock); overlap prevention on check-in; the daily record is a
  server-derived aggregate the client cannot set.
- **Authorized deviations only.** Remote/field/off-day attendance requires an
  `attendance_exception` created by an authorized actor — an employee can never
  self-declare; off-day work is never silently accepted (`off_day_work_policy`).
- **Segregation of duties (extended).** Overtime approval and exception approval
  forbid self-approval; correction and overtime approval use **optimistic
  concurrency** (stale record version → refused) so decisions never apply to
  outdated numbers. Every create/approve/reject/revoke/resolve is audited.
- **Neutral anomalies, no automated action.** Rule-based findings use neutral
  language (`suspicious_location_change`, never "fraud"), are advisory only, and
  never trigger disciplinary action; GPS jump detection uses coordinates already
  captured, and reports expose **no raw GPS**.
- **Materialization safety.** The daily processor runs per-tenant inside its own
  RLS context, is idempotent, isolates per-tenant failures, and never overwrites
  a real punch.

### Sprint 5 — Leave Management

- **FORCE RLS on every new table.** All eleven Sprint 5 leave tables carry
  `tenant_id` + FORCE RLS (`tenant_isolation` + `platform_readonly`); raw-SQL
  cross-tenant tests prove isolation, and the platform read-only context can
  SELECT but never write them.
- **Append-only ledger.** `leave_balance_transactions` has SELECT/INSERT policies
  only (no UPDATE/DELETE) plus a mutation-reject trigger — the balance source of
  truth is immutable for the application role (corrections are compensating
  reversal/adjustment rows).
- **Server-authoritative accounting.** Coverage and consumption are computed
  server-side from the schedule/holiday resolvers and the policy; the client never
  supplies balances, coverage, or consumption. Submit recalculates and reserves in
  one transaction under an advisory + row lock (no double-spend under races).
- **Segregation of duties.** Self-approval is impossible even for Owner/Admin
  (service-enforced); approvals use row locks + status guards + `version`
  (double-approval safe); the reservation→usage conversion runs exactly once.
- **Sensitive (medical) attachments** are private (S3-compatible, `storage_key`
  hidden, streamed/signed downloads) and gated by the distinct
  `leave.attachments.view_sensitive` — a leave viewer/approver does not
  automatically gain access; medical content never enters audit metadata, reports,
  the team calendar, or notifications.
- **Cross-tenant integrity.** Scope targets (branch/department/team/employee) are
  validated to belong to the acting tenant before assignment; per-employee actions
  are scope-checked with scope-safe 404s (no existence leak).
- **No money, no country rules, no notification transport** (Sprint 7 / Sprint 8
  boundaries respected).
