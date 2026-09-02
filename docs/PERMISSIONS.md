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

### 2.1 Organizational scopes within a tenant (ADR-015)

Inside a tenant, every role grant also carries an **organizational scope** that
bounds *which* resources the permission applies to:

| Scope | Meaning |
|---|---|
| **Company** | The whole tenant (all branches/departments/teams). |
| **Branch** | A specific branch and everything under it. |
| **Department / Team** | A specific department or team and its members. |

**An authorized manager may only access users and resources within their
assigned scope.** Authorization is therefore a three-part check:
**tenant → permission → organizational scope**. A grant is stored as
`(user, role, scope_type, scope_id)` (see `DATABASE.md` → `user_role`).
Company-scope grants have no `scope_id`; branch/department/team grants name the
specific node. A user may hold different roles at different scopes (e.g., HR at
company scope, Manager at one branch).

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

- **Three-part check** (ADR-015): every module evaluates **tenant → permission →
  organizational scope** before any read/write. A manager with a branch/team
  scope cannot reach resources outside it, even with the right permission.
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

## 8. Decision Status

Manager scoping is **decided (ADR-015)**: the authorization architecture supports
**Company / Branch / Department-Team** scopes, and managers are bounded to their
assigned scope. No open permission questions block Sprint 0.

---

## 9. Sprint 1 permissions (implemented)

Added to the catalog and to default role mappings (see
`app/Modules/Authorization/Support/PermissionCatalog.php`):

- **Organization:** `branches.{view,create,update,archive}`,
  `departments.{view,create,update,archive}`, `teams.{view,create,update,archive}`,
  `job_titles.{view,create,update,archive}`.
- **Employees:** `employees.{view,create,update,archive,transfer,link_user,view_sensitive}`.
- **Employee documents:** `employee_documents.{view,upload,delete}`.
- **Employee contracts:** `employee_contracts.{view,create,update,archive}`.

Default mappings: **Owner** = all (still tenant-scoped, no isolation bypass).
**Admin** = org full + employees full (incl. `view_sensitive`). **HR Manager** =
employees full + org view + department/team/job-title management.
**Department Manager** / **Team Leader** = scoped `employees.view`/`update`
(no `view_sensitive`). **Accountant** = employees + contracts view. **Employee**
= none (self-service only).

**Operational scope enforcement (ADR-015 made real):** role assignments carry
`scope_type` + `scope_id` against real Branch/Department/Team rows.
`EmployeeScopeResolver` converts a user's grants into query constraints and
row-level checks (department scope expands to its subtree). Route gates use
`permission.any:<key>` for scoped resources (admits any-scope holders) and
`permission:<key>` (company-scope) for org-structure management. Cross-scope
access returns a scope-safe **404**. `employees.view_sensitive` gates sensitive
fields in `EmployeeResource`; list endpoints never return sensitive fields.

---

## 10. Sprint 2 permissions (implemented)

Added tenant billing permissions (module `billing`):

- `billing.view`, `billing.manage`
- `billing.subscription.view`, `billing.subscription.change`
- `billing.invoices.view`, `billing.payments.view`
- `billing.bank_transfer.submit`

Default mappings: **Owner** = all (still tenant-scoped). **Admin** = full billing
(view/manage/subscription/invoices/payments/bank-transfer). **Accountant** =
`billing.view` + subscription/invoices/payments **view** + `bank_transfer.submit`
(no `billing.manage`, no `subscription.change`). **HR Manager / Department
Manager / Team Leader / Employee** = no billing by default. Billing gates use
`permission:<key>` (company scope) since billing is company-wide, not org-scoped.
**Platform (Super Admin) billing** is a separate identity/guard and is never part
of tenant RBAC.

## 11. Sprint 3 permissions (implemented)

Added attendance permissions (module `attendance`):

- `attendance.view`, `attendance.view_location` (sensitive precise GPS)
- `attendance.manage` (record/manual entry), `attendance.corrections.review`
- `attendance.schedules.view`, `attendance.schedules.manage`
- `attendance.locations.manage`, `attendance.settings.manage`
- `attendance.reports.view`

**Employee self-service** (own check-in/out, own attendance, own correction
request) is **not** a permission — it requires an authenticated, employee-linked
user. These keys gate viewing/administering **other** employees.

Default mappings: **Owner / Admin / HR Manager** = full attendance set.
**Department Manager** = view, manage, corrections.review, schedules.view,
reports.view (scoped). **Team Leader** = view + reports.view. **Accountant /
Employee** = none by default.

**Gating model:** company-wide config (settings, schedules, locations) uses
`permission:<key>` (company scope). Operations over specific employees (records,
manual entry, corrections, reports) use `permission.any:<key>` and are further
constrained to the caller's organizational scope by `EmployeeScopeResolver`.
Precise GPS coordinates are exposed only via `attendance.view_location`
**within a scope covering that employee** (scope-aware, NB-1) — or to the
employee viewing their own record.

### Sprint 4 — Attendance Advanced permissions

- `attendance.holidays.view`, `attendance.holidays.manage` (company scope —
  holiday calendars are shared config)
- `attendance.exceptions.view`, `attendance.exceptions.manage` (org scope)
- `attendance.overtime.view`, `attendance.overtime.review` (org scope)
- `attendance.overtime.override` (org scope) — approve overtime ABOVE the
  server-calculated amount; a distinct privilege never implied by review. Owner
  (via `*`) and Admin hold it by default; HR Manager reviews but does not
  override unless a custom role grants it; Department Manager / Team Leader /
  Employee never.
- `attendance.anomalies.view`, `attendance.anomalies.manage` (org scope)
- `attendance.materialization.run` (company scope — on-demand daily run)

Default mappings: **Owner / Admin / HR Manager** = full Sprint 4 set.
**Department Manager** = holidays.view, exceptions.view/manage, overtime.view/
review, anomalies.view/manage (scoped); does **not** manage company-wide holiday
calendars. **Team Leader** = holidays.view (+ Sprint 3 view). **Employee** = none
(self-service unaffected). Overtime review and exception approval never allow the
employee to act on their own records (segregation of duties, service-enforced).
