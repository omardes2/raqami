# Permissions & Roles (RBAC) — Raqmi Dawam

**Status:** Design (planning phase). Defines the role-based access control model
with granular, module-level permissions. Sensitive data (payroll, national IDs,
bank details) is always permission-gated.

---

## 1. Principles

- **Least privilege:** users get only what their role grants.
- **Tenant-scoped:** every permission check is evaluated within the acting
  user's tenant. Cross-tenant access is prohibited (`CLAUDE.md` rules 3–4).
- **Granular:** permissions are per action, grouped by module.
- **Deny by default:** absence of a permission means no access.
- **Auditable:** permission and role changes are recorded in audit logs.
- **Separation of duties:** sensitive operations (payroll approval, billing) can
  be split across roles.

## 2. Scopes

There are two permission universes:

1. **Platform (central) scope** — for **Super Admin** users who operate the
   platform (manage tenants, plans, billing, platform health). Explicitly and
   narrowly scoped; all cross-tenant actions are audited.
2. **Tenant scope** — for **Company Admin, HR, Manager, Employee**, and custom
   roles inside a single company.

A tenant-scoped user can **never** be granted platform-scope permissions.

## 3. System Roles (defaults)

### Platform scope
| Role | Purpose |
|---|---|
| **Super Admin** | Full platform operations, tenant lifecycle, billing oversight, support. |
| **Support Agent** (optional) | Limited, audited read/assist across tenants for support. |

### Tenant scope
| Role | Purpose |
|---|---|
| **Owner** | Company owner; full control of the tenant, including billing. |
| **Company Admin** | Administers org, staff, attendance, payroll config. |
| **HR** | Employees, leave, attendance oversight, reports. |
| **Manager** | Team/department: attendance, tasks, leave approvals for reports. |
| **Finance** | Payroll runs, payslips, payments (sensitive). |
| **Employee** | Self-service: own attendance, leave, tasks, payslips. |

Companies may create **custom roles** by composing permissions.

## 4. Permission Catalog (module.action)

> Naming convention: `module.action`, optionally `module.entity.action`.
> Actions commonly include `view`, `create`, `update`, `delete`, `approve`,
> `export`, `manage`.

### Organization
- `company.view`, `company.update`
- `branch.view`, `branch.manage`
- `department.view`, `department.manage`
- `employee.view`, `employee.create`, `employee.update`, `employee.delete`
- `employee.sensitive.view` (national ID, bank details) — **sensitive**
- `team.view`, `team.manage`

### Access Control
- `role.view`, `role.manage`
- `permission.assign`
- `user.invite`, `user.manage`

### Attendance
- `attendance.view`, `attendance.view.own`
- `attendance.check` (perform check-in/out)
- `attendance.adjust` (edit records) — sensitive/audited
- `schedule.view`, `schedule.manage`
- `shift.view`, `shift.manage`
- `geofence.view`, `geofence.manage`

### Leave
- `leave.request` (own), `leave.view`, `leave.view.own`
- `leave.approve`
- `leave.policy.manage`

### Tasks
- `task.view`, `task.view.own`, `task.create`, `task.update`,
  `task.assign`, `task.delete`
- `task.ai.generate`

### Payroll (sensitive)
- `payroll.view` — **sensitive**
- `payroll.run`
- `payroll.approve` — separation of duties from `payroll.run`
- `payslip.view`, `payslip.view.own`, `payslip.export`

### Billing & Subscription
- `billing.view`, `billing.manage`
- `subscription.change`
- `payment.record` (manual/bank), `payment.view`

### Reports
- `report.view`, `report.export`
- `report.ai.generate`

### Notifications
- `notification.view.own`, `notification.manage`

### Audit
- `audit.view` (tenant-scoped audit trail)

### AI
- `ai.assistant.use`
- `ai.insights.view`
- `ai.workload.view`

### Platform (Super Admin only)
- `platform.tenant.view`, `platform.tenant.manage`
- `platform.plan.manage`
- `platform.billing.view`, `platform.billing.manage`
- `platform.payment.record`
- `platform.audit.view`
- `platform.support.access` (audited cross-tenant assist)

## 5. Default Role → Permission Matrix (illustrative)

| Permission group | Owner | Company Admin | HR | Manager | Finance | Employee |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Company/org manage | ✅ | ✅ | partial | — | — | — |
| Employees manage | ✅ | ✅ | ✅ | view team | — | — |
| Employee sensitive view | ✅ | ✅ | ✅ | — | ✅ | own |
| Roles & permissions | ✅ | ✅ | — | — | — | — |
| Attendance config | ✅ | ✅ | ✅ | — | — | — |
| Attendance check (own) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Attendance adjust | ✅ | ✅ | ✅ | team | — | — |
| Leave approve | ✅ | ✅ | ✅ | team | — | — |
| Tasks manage | ✅ | ✅ | ✅ | team | — | own |
| Payroll run | ✅ | ✅ | — | — | ✅ | — |
| Payroll approve | ✅ | — | — | — | ✅* | — |
| Payslip view (own) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Billing manage | ✅ | ✅ | — | — | view | — |
| Reports | ✅ | ✅ | ✅ | team | finance | own |
| Audit view | ✅ | ✅ | — | — | — | — |
| AI assistant | ✅ | ✅ | ✅ | ✅ | ✅ | limited |

`*` Separation of duties: the person who **runs** payroll should not be the sole
person who **approves** it; configurable per tenant.

> This matrix is a starting default; tenants can adjust via custom roles.

## 6. Enforcement

- **Policy checks** are evaluated in every module before any read/write.
- Sensitive fields are stripped from responses unless the user holds the
  relevant `*.sensitive.view` or `payroll.view` permission.
- **AI features respect permissions:** the assistant can only access data the
  requesting user is permitted to see.
- **Tests are mandatory** for permission enforcement and for the guarantee that
  no role can reach another tenant's data (`CLAUDE.md` rule 7).

## 7. Auditing

Role creation/changes, permission grants/revokes, and any use of sensitive or
platform permissions are recorded in audit logs (`SECURITY.md`).

## 8. Open Questions

- Do we need per-branch/department scoping on manager permissions in v1, or is
  team-based scoping sufficient? (See `DECISIONS.md`.)
