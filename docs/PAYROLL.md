# Payroll & Payslips — Raqmi Dawam

**Status:** Design (planning phase). Covers payroll runs, calculation inputs,
approval workflow, payslips, and the strict permissions/audit requirements
around sensitive compensation data.

---

## 1. Goals

- Produce accurate, auditable payroll derived from attendance, leave, and
  policy data.
- Protect all compensation data behind permissions.
- Generate bilingual (Arabic/English) payslips.
- Make approved payroll **immutable** and fully audited.

## 2. Inputs

Payroll for a period is computed from:
- **Base salary / rate** per employee.
- **Attendance:** worked time, lateness/absence effects (per policy).
- **Overtime:** from the attendance engine and overtime policy.
- **Leave:** paid vs unpaid leave (from leave management).
- **Allowances:** fixed/variable (housing, transport, etc.).
- **Deductions:** loans, penalties, taxes/contributions (as configured).

> Tax/contribution rules are region-specific and configurable; exact rulesets
> are an open decision (see `DECISIONS.md`).

## 3. Payroll Run Lifecycle

```
draft ─► processing ─► review ─► approved ─► paid
   │                                 │
   └──────────── cancelled ◄─────────┘  (before approval)
```

- **draft:** period selected, inputs gathered.
- **processing:** async job computes per-employee items.
- **review:** results checked; adjustments allowed (audited).
- **approved:** locked; **immutable** thereafter (`CLAUDE.md` rules 2, 6).
- **paid:** payments/disbursement recorded.

**Separation of duties:** `payroll.run` and `payroll.approve` should be held by
different people (configurable per tenant) — see `PERMISSIONS.md`.

## 4. Calculation (conceptual)

For each employee in the period:
```
gross  = base + allowances + overtime_amount + adjustments
net    = gross − deductions
```
- All money stored as integer minor units + currency (no floats).
- Full **breakdown** retained per item for transparency and audit.
- Rounding rules explicit and consistent (region policy → `DECISIONS.md`).

## 5. Payslips

- One payslip per employee per run.
- Rendered in the employee's **locale** (Arabic RTL / English LTR).
- Stored as documents (S3-compatible storage); accessible per permission
  (`payslip.view.own` for employees).
- Contain: employer/employee identity, period, earnings, deductions, net pay,
  and a breakdown. No secrets beyond what the recipient may see.

## 6. Data Touchpoints

See `DATABASE.md`: `payroll_runs`, `payroll_items` (**sensitive**), `payslips`.

## 7. Permissions (sensitive)

- `payroll.view` — view payroll data (**sensitive**, gated).
- `payroll.run` — execute a run.
- `payroll.approve` — approve (separation of duties).
- `payslip.view` / `payslip.view.own` / `payslip.export`.

Employees see **only their own** payslips. Payroll amounts are never exposed by
default APIs/reports.

## 8. Security & Audit

- Every run action (create, adjust, approve, mark paid) is **audited** with
  actor and before/after (redacted) — `SECURITY.md`.
- Compensation and bank details are encrypted/permission-gated.
- Approved payroll and payslips are immutable; corrections are made via a new,
  audited adjustment run, never by editing history.

## 9. Testing (when implemented)

Mandatory coverage for: calculation correctness (gross/net, overtime, leave
effects, rounding), separation-of-duties enforcement, immutability after
approval, payslip localization, and tenant isolation of payroll data.

## 10. Open Questions

- Regional tax/contribution rulesets and statutory payslip fields.
- Disbursement integrations (bank files, payment providers) — future.
- Multi-currency payroll for multinational tenants? (See `DECISIONS.md`.)
