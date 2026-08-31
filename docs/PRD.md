# Product Requirements Document — Raqmi Dawam

**Status:** Draft (planning phase) · **Owner:** Project Owner · **Last updated:** 2026-08-31

---

## 1. Overview

Raqmi Dawam is a multi-tenant SaaS workforce management platform. It lets
companies register, subscribe to a plan, onboard their organization
(branches, departments, employees), and run day-to-day workforce operations:
attendance, leave, tasks, and payroll — with reporting, notifications, audit
logging, and AI assistance. The product is bilingual (Arabic RTL / English LTR)
and is designed to scale to thousands of companies and hundreds of thousands of
employees.

## 2. Goals

- Provide a reliable, secure, multi-tenant platform with **complete data
  isolation** per company.
- Make attendance tracking accurate and flexible (shifts, grace periods,
  overtime, GPS, geofencing).
- Automate payroll from attendance and policy data with auditable results.
- Offer clear subscription/billing with multiple payment methods.
- Deliver a first-class bilingual experience (Arabic RTL, English LTR).
- Layer AI on top to reduce manual work and surface insight.

## 3. Non-Goals (for now)

- No native mobile apps in the initial scope (a **mobile API** is planned so
  apps can be built later).
- No third-party HRIS/ERP integrations in the foundation phase.
- No on-premise deployment; the product is cloud SaaS.

## 4. Personas

| Persona | Description | Primary needs |
|---|---|---|
| **Platform Operator (Super Admin)** | Runs Raqmi Dawam | Manage tenants, plans, billing, platform health, support |
| **Company Admin / Owner** | Administers a tenant company | Onboard org, configure attendance/payroll, manage staff & billing |
| **HR / Manager** | Departmental or company HR | Approve leave, review attendance, run reports, assign tasks |
| **Employee** | Individual staff member | Check in/out, request leave, view tasks & payslips |
| **Finance** | Company finance role | Run payroll, view payslips, manage payments |

## 5. Product Scope & Functional Requirements

### 5.1 Multi-Tenant SaaS
- FR-1 Every company is an isolated tenant; no cross-tenant data access.
- FR-2 A company registers, verifies, and is provisioned as a tenant.
- FR-3 Tenant lifecycle: trialing → active → past_due → suspended → cancelled.

### 5.2 Company Registration & Onboarding
- FR-4 Self-service registration with email verification.
- FR-5 Guided onboarding wizard: company profile, branches, departments,
  first employees, working-hours defaults.
- FR-6 Choose/confirm a subscription plan during onboarding.

### 5.3 Subscription Plans & Billing
- FR-7 Configurable plans (tiers, employee limits, feature flags, price,
  billing cycle, currency).
- FR-8 Subscriptions with trials, upgrades/downgrades, proration, cancellation.
- FR-9 Payments via **Visa/Mastercard** (card gateway), **bank transfer**, and
  **manual/cash** (recorded by Super Admin/Company Admin with proof).
- FR-10 Invoices and receipts; dunning for failed payments.

### 5.4 Organization
- FR-11 Branches (locations), departments, and their hierarchy.
- FR-12 Employees with profile, employment details, and org placement.
- FR-13 Teams that group employees across/within departments.

### 5.5 Roles & Granular Permissions
- FR-14 Role-based access control with granular, module-level permissions.
- FR-15 System roles (Owner, Admin, HR, Manager, Employee) plus custom roles.
- FR-16 Sensitive data (payroll, national ID, bank details) is permission-gated.

### 5.6 Attendance
- FR-17 Check-in / check-out (web, and later mobile via API).
- FR-18 Configurable working days & hours per company/branch/employee.
- FR-19 Shifts, including rotating/overnight shifts.
- FR-20 Grace periods for late arrival / early leave.
- FR-21 Overtime calculation based on policy.
- FR-22 GPS attendance capture.
- FR-23 Geofencing: allow/deny check-in based on location boundaries.

### 5.7 Leave Management
- FR-24 Leave types, policies, and balances/accruals.
- FR-25 Request → approval workflow with delegation.
- FR-26 Leave reflected in attendance and payroll.

### 5.8 Tasks & Team Management
- FR-27 Create, assign, track tasks with status, priority, due dates.
- FR-28 Team management and task visibility by role.
- FR-29 AI-assisted task generation (see AI section).

### 5.9 Payroll & Payslips
- FR-30 Payroll runs derived from attendance, leave, overtime, allowances,
  deductions.
- FR-31 Payslip generation (bilingual, exportable).
- FR-32 Payroll approval workflow and immutable records post-approval.

### 5.10 Reports
- FR-33 Attendance, leave, payroll, and workforce reports with filters/export.
- FR-34 AI-generated reports and insights.

### 5.11 Notifications
- FR-35 In-app and email notifications; extensible channels (SMS/push later).
- FR-36 Event-driven (e.g., late check-in, leave decision, payroll ready).

### 5.12 Audit Logs
- FR-37 Immutable audit trail of important actions (see `SECURITY.md`).

### 5.13 AI Assistant & Insights
- FR-38 Conversational assistant scoped to a tenant's data and the user's
  permissions.
- FR-39 AI reports/insights, AI task generation, AI workload analysis.

### 5.14 Localization
- FR-40 Full Arabic (RTL) and English (LTR) support across UI, emails, exports.
- FR-41 No hard-coded translations; all strings from i18n resources.

### 5.15 Portals
- FR-42 Super Admin portal (platform operations).
- FR-43 Company Admin portal (tenant administration).
- FR-44 Employee portal (self-service).
- FR-45 Mobile-ready API for a future mobile application.

## 6. Non-Functional Requirements

- **NFR-1 Security:** tenant isolation, encryption in transit and at rest for
  sensitive data, least-privilege access. See `SECURITY.md`.
- **NFR-2 Scalability:** support thousands of tenants and hundreds of thousands
  of employees; horizontally scalable stateless app tier.
- **NFR-3 Availability:** target high availability; graceful degradation.
- **NFR-4 Performance:** common operations (check-in, dashboards) responsive
  under load; heavy work (payroll, reports, AI) runs asynchronously.
- **NFR-5 Auditability & compliance:** important actions logged; data handling
  respects regional data-protection expectations.
- **NFR-6 Localization:** correct RTL/LTR rendering, locale-aware dates,
  numbers, and currencies.
- **NFR-7 Maintainability:** modular, tested, documented; sprint-based delivery.
- **NFR-8 Observability:** logging, metrics, and tracing for operations.

## 7. Assumptions

- Cloud-hosted, single logical product with regional deployment options later.
- Payment gateway(s) to be selected during the billing sprint (see DECISIONS).
- AI provider(s) to be selected during the AI sprint (see DECISIONS).

## 8. Success Metrics (initial)

- Time-to-onboard a new company.
- Attendance capture accuracy / dispute rate.
- Payroll run success rate and time.
- Subscription conversion and churn.
- Tenant isolation incidents: **must remain zero**.

## 9. Open Questions (for owner)

See the consolidated list in [`docs/DECISIONS.md`](DECISIONS.md) → "Decisions
requiring owner approval".
