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
