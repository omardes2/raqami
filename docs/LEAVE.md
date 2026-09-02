# Leave Management — Raqmi Dawam

**Status:** **Implemented in Sprint 5** (`feature/sprint-5-leave-management`).
A production-grade, global-SaaS leave system built on the Sprint 3/4 attendance
engine. Canonical accounting is the **integer minute**; there is **no money**,
**no country-specific labor rule**, and **no notification transport** (Sprint 7 /
Sprint 8 boundaries).

Governing principle (as with attendance): the **server decides** every result.
The client never supplies coverage, consumption, or balances.

---

## 1. Canonical unit — integer minutes

All entitlement, accrual, balance, reservation, and usage are stored and computed
in **integer minutes**. A "day" of leave is *whatever the employee was scheduled
to work that date* (resolved by the Sprint 4 `ScheduleResolver`) — so 6h/8h/10h
and split-shift schedules are all exact, with no floating-point drift. Days/hours
are a **display-only** conversion (`leave_settings.display_day_minutes`,
default 480). No global 8-hour-day assumption exists in the backend.

## 2. Types & policies

- **`leave_types`** — tenant catalog (annual/sick/unpaid/emergency/parental/
  compensatory/custom). `category` is a generic grouping only — it encodes **no**
  entitlement amount or legal rule. `paid_classification` is a future-Payroll hint.
- **`leave_policies`** — the rule set, kept **separate** from the type: entitlement
  (none/fixed/accrual), accrual (monthly/annual), carry-forward + expiry, balance
  caps, negative allowance, request limits (min/max minutes, notice, advance
  booking), consumption basis (§4), half-day, approval flow, withdrawal/cancellation
  toggles. Effective-dated.
- **`leave_policy_assignments`** — bind a policy to a scope. **`LeavePolicyResolver`**
  resolves the winning policy with the **same deterministic precedence as
  `ScheduleResolver`**: employee > team > department (deepest ancestor first) >
  branch > company, tie-broken by priority desc, effective_from desc, created_at
  desc, id desc.

## 3. Entitlement periods

Per (employee, leave_type) accounting windows — **never assume Jan 1**. Supported
bases: **calendar year**, **employment anniversary** (from `hire_date`), and a
**custom tenant year** (`leave_settings.leave_year_start_month/day`).
`LeaveEntitlementPeriodService` resolves/creates the period containing a date by
exact date arithmetic.

## 4. Consumption basis (D7) vs coverage

Two distinct per-date quantities, both snapshotted on `leave_request_days`:

- **`coverage_minutes`** — expected-work minutes the leave covers (drives
  **attendance**). Zero on a holiday/non-working date.
- **`consumption_minutes`** — minutes deducted from the **balance**, per the
  policy's `consumption_basis`:
  - **`scheduled_minutes`** (default): consume the covered expected work; a
    non-working day consumes zero.
  - **`nominal_calendar_day`**: consume the policy's `nominal_day_minutes` for each
    counted date, even where no work was scheduled (e.g. calendar-day leave).

**Contradiction guard:** `count_holidays` / `count_non_working_days` require the
`nominal_calendar_day` basis (with `nominal_day_minutes > 0`) — rejected with a
localized 422 otherwise. **Balance consumption and attendance status are separate
concepts:** a nominal policy may consume balance on a holiday while attendance
still classifies the day as `holiday`.

## 5. Immutable ledger + projection

- **`leave_balance_transactions`** is the **append-only source of truth** (RLS:
  SELECT/INSERT only + a mutation-reject trigger, like `audit_logs`). Types: grant,
  accrual, carry_forward, expiry, reservation, reservation_release, usage,
  usage_reversal, adjustment, adjustment_reversal. `minutes` is signed relative to
  its bucket; a stable `idempotency_key` makes processors re-run-safe.
- **`leave_balances`** is a transactionally-maintained **projection + lock row**,
  rebuildable from the ledger. `available = granted + accrued + carried + adjusted
  − used − reserved − expired`.

**Reservation → usage deducts exactly once:** submit posts a `reservation`
(availability drops immediately); final approval posts `reservation_release`
+ `usage` in one step (net-zero at approval); reject/withdraw release the
reservation; cancellation reverses usage — each once, under an advisory + row lock.

## 6. Request lifecycle

`pending → approved | rejected | withdrawn`, and `approved → cancellation_pending
→ cancelled` (plus HR/Admin `approved → cancelled` direct). **No server draft** —
lifecycle begins at submit. Submit validates, snapshots one `leave_request_days`
row per logical work_date (schedule/holiday/coverage/consumption/basis frozen),
reserves the balance, and builds the approval workflow — all in one transaction.
A non-authoritative **preview** endpoint mirrors the calculation; the final submit
recalculates server-side.

**Half-day (D1):** `full_day | first_half | second_half`, derived as **coverage
intervals** over the ordered expected-work minutes (boundary `ceil(T/2)`; may fall
between or inside a segment). No arbitrary hourly leave in V1; the interval model
is hourly-ready.

**Overlap** is coverage-interval aware — two non-overlapping halves may coexist.
Overnight schedules attach leave to the logical work_date (no midnight split).

## 7. Approvals

Snapshotted at submission (`leave_request_approvals`), so a later transfer never
reroutes a pending request. Default routing: **direct manager → department manager
→ HR pool** (Team Lead only if a policy configures it; optional Manager → HR).
The **HR pool** is an RBAC set, not a directory: an `hr_pool` step carries no user
and is actionable by any holder of `leave.approve` within a scope covering the
employee. **Self-approval is impossible** (service-enforced, even for Owner/Admin)
— a self-resolving approver is skipped/escalated. Concurrency-safe (request + step
row locks, status guards, `version`); the reservation→usage conversion runs once.

## 8. Withdrawal & cancellation

- **Withdraw** (pending): terminal; releases the reservation; cancels open steps.
  Idempotent.
- **Cancellation request** (approved): `cancellation_pending`; the leave stays
  **ACTIVE** for `LeaveResolver`/attendance and **balance is not restored** until a
  manager/HR finalizes. **Final cancellation** reverses the future (not-yet-elapsed)
  usage once and re-materializes freed attendance days. HR/Admin may direct-cancel
  with a **mandatory reason**. Nothing is ever destructively deleted.

## 9. Accrual / carry-forward / expiry

- `leave:process-accruals` — fixed upfront grants + monthly/annual accrual, capped
  by `max_balance`, idempotent.
- `leave:process-periods` — carry-forward (capped by `carry_forward_max_minutes`)
  into the next period + expiry of the **non-carried remainder** at period close,
  idempotent (stable ledger keys; re-runs never duplicate carry/expiry).
- Both run per-tenant (failure-isolated), scheduler-ready; **no cron is wired**.
  First-year proration is an off-by-default extension hook (D4).

**Carried-balance expiry-after-N-days is NOT implemented in Sprint 5.** The
`leave_policies.carry_forward_expiry_days` column exists but is **reserved/future
only** — it is not accepted by the policy API, not returned by the resource, and
not surfaced in the UI. What *is* implemented: carry-forward, the carry maximum,
and period-close expiry of the non-carried excess.

### Request editing

There is **no** direct edit of a submitted request's dates/type/portion — a
generic update route does not exist. To change a pending request the employee
**withdraws** (releasing the reservation) and submits a new one; an approved
request is changed via cancellation + a new request. This keeps accounting and
approval routing rebuilt from scratch rather than mutated behind a reservation.

## 10. Attachments & medical privacy

Private S3-compatible storage (`leave_request_attachments`), tenant-prefixed keys,
metadata-only rows (`storage_key` hidden), authorized streamed/signed downloads,
never a public URL, no binary in the DB. `requires_attachment` is enforced at
**approval** (the employee may submit — reserving balance — then upload the
document while pending; approval is blocked until it exists). A derived
`missing_required_attachment` flag is returned on the request so the UI shows
"supporting document required before approval". **Sensitive (medical) attachments**
require the distinct
`leave.attachments.view_sensitive` permission — a leave viewer/approver does **not**
automatically gain access; the employee always can. Medical content never enters
audit metadata, reports, notifications, or the team calendar.

## 11. Attendance integration

Attendance depends on a single authority, **`LeaveResolver`** (never ad-hoc leave
queries), which returns merged approved/cancellation_pending coverage intervals
for a work_date. `attendance_records` gained **no** leave column (a day may carry
several partial leaves — resolved through `leave_request_days`).

- **Full coverage** of expected work → `AttendanceStatus::OnLeave` (no absent).
- **Partial coverage** → attendance expects only the **remaining** work
  (`expected − coverage`); a punch during covered time is not late, and absence for
  an uncovered remainder still applies.
- **Materializer precedence:** eligibility → real-punch short-circuit (never
  overwritten) → Holiday → full leave (OnLeave) → Weekend/off → remaining-work
  absence after cutoff. Holiday > OnLeave > Weekend > Absent.
- Real work during approved leave is **preserved** (no auto-refund/cancel/delete;
  a `worked_during_approved_leave` reporting hook is future work).

## 12. Permissions & roles

`leave.view_own`, `leave.request` (self-service reference), `leave.view`,
`leave.manage`, `leave.approve`, `leave.types.*`, `leave.policies.*`,
`leave.balances.view`, `leave.balances.adjust`, `leave.negative_override`,
`leave.attachments.view_sensitive`, `leave.reports.view`, `leave.settings.manage`.
`negative_override` and `attachments.view_sensitive` are **distinct privileges**
excluded from the shared `LEAVE_FULL` set. Defaults: Owner/Admin = full (+ both
distinct privileges); HR Manager = full + sensitive attachments (no override);
Department Manager = scoped view/approve/reports/balances.view; Team Leader = view
only (approval only if a custom role grants it); Employee = self-service (auth +
employee link, not a permission). Gating mirrors attendance: company config via
`permission:`, per-employee actions via `permission.any:` + `EmployeeScopeResolver`
with scope-safe 404s.

## 13. Security, audit, RLS

FORCE RLS (`tenant_isolation` + `platform_readonly`) on all eleven leave tables;
the ledger is additionally append-only; raw-SQL cross-tenant isolation is tested.
Cross-tenant scope targets are validated explicitly. Audited actions: type/policy
create/update/archive/assign; request submit/approve/reject/withdraw/cancellation-
requested/cancelled; ledger grant/accrual/carry/expiry/adjustment/reservation/
release/usage/reversal; attachment upload/delete (metadata only — never contents).

## 14. Out of scope (Sprint 5)

Payroll/salary/money; country-specific statutory entitlements; per-pay-period or
tenure-tier accrual; arbitrary hourly/intra-segment leave (model-ready, not built);
delegation/out-of-office routing; encashment; notification delivery (Sprint 8 —
audit + hooks only); automatic balance reclaim for work during approved leave;
carry-forward-expiry-days scheduling (carry + period-end expiry are implemented).
