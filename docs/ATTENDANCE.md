# Attendance Engine — Raqmi Dawam

**Status:** **Core implemented in Sprint 3** (on `feature/sprint-3-attendance-core`).
Covers check-in/out, configurable working days & hours, overnight windows, grace
periods, overtime foundation, GPS attendance, and backend geofencing. Advanced
rotating shifts and labor-law rounding remain design-only (Sprint 4+).

> **Sprint 3 V1 notes (see `DECISIONS.md` → Sprint 3 Implementation Notes):**
> UTC storage + schedule-timezone computation; the SERVER decides every result
> (instant, schedule, geofence, lateness, worked time, status) — the client only
> sends GPS facts. `work_date` is the schedule-timezone local date of the punch;
> an overnight window extends the end into the next day (it does not reach back).
> Schedule boundaries are snapshot at check-in; a single `ScheduleResolver`
> (precedence employee > team > department > branch > company) and a single
> `AttendanceCalculator` are the authorities. Records (daily rollup) vs events
> (append-only raw punches). Concurrency via advisory + row locks; idempotent
> retries via `client_request_id`. FORCE RLS on all attendance tables.

---

## 1. Goals

- Accurate, flexible attendance for diverse companies and shift patterns.
- Configurable at company, branch, department, and employee levels.
- Location-aware (GPS + geofencing) with clear enforcement modes.
- Feed clean, auditable data into leave, reports, and payroll.
- Work in both web and (future) mobile via the API.

## 1a. V1 Scope (ADR-017)

**Primary attendance methods in V1:** **web/mobile check-in, GPS, and
geofencing.** **Biometric devices, face recognition, and kiosk integrations are
future features** and are out of scope for the first release.

## 2. Concepts

### 2.1 Working Schedules (working days & hours)
A **work schedule** defines the standard working week: which days are working
days and the expected hours per day, plus grace and overtime policy. Schedules
attach at a **scope**: `company` (default), `branch`, `department`, or
`employee`. The most specific applicable schedule wins.

Example week definition (conceptual JSON):
```json
{
  "timezone": "Asia/Riyadh",
  "days": {
    "sun": { "working": true,  "start": "09:00", "end": "17:00" },
    "mon": { "working": true,  "start": "09:00", "end": "17:00" },
    "fri": { "working": false }
  }
}
```

### 2.2 Shifts
A **shift** is a named working window (`start_time`, `end_time`, breaks). Shifts
may **cross midnight** (overnight shifts). Employees receive **shift
assignments** for dates or ranges, supporting **rotating** patterns.

### 2.3 Grace Periods
Per schedule/shift, a **grace-in** and **grace-out** window (minutes). Arrival
within grace is on-time; beyond grace is **late** (late minutes recorded).
Similarly for early leave.

### 2.4 Overtime
Time worked beyond the scheduled/shift hours (and outside breaks) accrues
**overtime** per the tenant's **overtime policy** (e.g., daily threshold,
weekly threshold, rate multipliers, caps, approval required). Overtime minutes
flow to payroll.

### 2.5 GPS Attendance
On check-in/out, the client may capture **latitude/longitude** (and accuracy).
Coordinates are stored on the attendance record for verification and reporting.

### 2.6 Geofencing
A **geofence** defines an allowed area (circle: center + radius, or a polygon)
usually tied to a branch. Enforcement modes:
- `enforce` — check-in/out **rejected** outside the fence.
- `warn` — allowed but flagged for review.
- `off` — location captured, not enforced.

## 3. Check-in / Check-out Flow

```
Employee action (web/mobile)
   │
   ├─ Resolve applicable schedule + shift for (employee, date)
   ├─ Capture timestamp (server-authoritative) + optional GPS
   ├─ Geofence check (enforce | warn | off)
   ├─ Compute: late_minutes / early_leave / worked / overtime
   ├─ Persist attendance_record (source, coords, flags, status)
   └─ Emit events → notifications, audit, AI/reporting
```

Rules:
- **Server time is authoritative** for the recorded timestamp; client time is
  informational.
- Double check-ins / missing check-outs are detected and flagged for review.
- Manual entries/adjustments require `attendance.adjust` and are **audited**.
- All computations respect the schedule's **timezone**.

## 4. Statuses

`present`, `late`, `absent`, `on_leave`, `holiday`, `pending_review`.

Derived at the end of a day/shift by a scheduled job that reconciles check-ins,
leave records, and holidays.

## 5. Edge Cases

- **Overnight shifts:** a check-out after midnight maps to the correct shift/day.
- **Split shifts / breaks:** breaks excluded from worked minutes.
- **Rotating shifts:** driven by assignment rotation definition.
- **Holidays & weekends:** per schedule and a holiday calendar (tenant-level).
- **DST / timezone changes:** computations anchor to the schedule timezone.
- **Poor GPS accuracy:** low-accuracy reads flagged; policy decides accept/warn.
- **Offline (future mobile):** queued check-ins with client timestamp, marked
  and reconciled server-side.

## 6. Data Touchpoints

See `DATABASE.md`: `work_schedules`, `shifts`, `shift_assignments`, `geofences`,
`attendance_records`. `attendance_records` is high-volume and a candidate for
monthly partitioning.

## 7. Reports & AI

- Attendance summaries (per employee/team/department/branch), lateness trends,
  overtime totals, absence patterns.
- AI **workload analysis** and anomaly hints (e.g., unusual overtime) — see
  `AI-FEATURES.md`. AI respects permissions and tenant isolation.

## 8. Security & Audit

- Attendance adjustments, geofence changes, and schedule/shift changes are
  **audited**.
- GPS coordinates are personal data: access is permission-gated and retention
  is policy-bound (`SECURITY.md`).

## 9. Testing (when implemented)

Mandatory coverage for: grace-period math, overtime computation, overnight
shifts, geofence enforce/warn/off, timezone correctness, and tenant isolation of
attendance data.

## 10. Decision Status & Open Questions

- **Decided (ADR-017):** V1 methods are web/mobile check-in, GPS, geofencing;
  biometric/face/kiosk are deferred.
- **Open (does not block V1):** overtime policy defaults and legal rounding rules
  per region — tied to the first country payroll rule provider (see
  `PAYROLL.md`, `DECISIONS.md`).

---

## 11. Sprint 4 — Attendance Advanced

Sprint 4 extends (never replaces) the Sprint 3 engine. All Sprint 3 invariants
still hold: the **server** is authoritative for time, work_date, schedule,
geofence, calculations, and status; Employee ≠ User; UTC storage; FORCE RLS on
every tenant table.

### 11.1 Sessions & the daily aggregate

- **`attendance_sessions`** holds each individual check-in/out. A `work_date`
  may carry several **closed** sessions (split shifts); **at most one open**
  session per employee (partial unique index + advisory lock).
- **`attendance_records`** is now the **daily aggregate**, recomputed from its
  sessions by `AttendanceRecordAggregator` (sum of worked/late/early/overtime,
  first check-in / last check-out, derived status). A `version` counter supports
  optimistic concurrency.
- `allow_multiple_sessions` (default **off**) preserves Sprint 3
  single-session-per-day behavior; tenants opt in to split shifts.

### 11.2 Advanced schedules

- **Split shifts** via `work_schedule_segments` (several expected windows per
  day). Each session is calculated against the segment nearest its check-in.
- **Rotation** via `work_schedules.cycle_length_days` + `anchor_date`; on a
  cyclic schedule `work_schedule_days.weekday` is reinterpreted as the
  cycle-day-index. Overnight reach-back is generalized to per-segment windows.

### 11.3 Holidays

`holiday_calendars` → `holidays` (single or multi-day) assigned to company or
branch via `holiday_calendar_assignments`. `HolidayResolver` applies **branch >
company** precedence. A holiday **overrides** a scheduled working day: no absence
is ever materialized on a holiday.

### 11.4 Daily materialization

`AttendanceDayMaterializer` derives the state of employees who did not punch:
**weekend/off**, **holiday**, **absent** (only **after** the configured cutoff,
never at midnight), and flags an open record past day-end as **incomplete**. A
**real punch is never overwritten**. `AttendanceDailyProcessor` +
`attendance:process-daily` run it across all tenants, each in its own RLS
context; idempotent (re-running yields the same result).

### 11.5 Exceptions (remote / field / off-day / alternate)

`attendance_exceptions` record **authorized** deviations created by HR/managers
(never self-declared). `ExceptionResolver` returns the active exception for a
date; check-in honors it for geofence (remote/field skip the office geofence)
and for the `off_day_work_policy` (`reject` / `allow` / `require_approval`).

### 11.6 Overtime approval

Raw server-computed overtime (`calculated_minutes`) is kept **separate** from
reviewer-decided `approved_minutes` (only approved overtime feeds future
payroll). No self-approval; no over-approval without an explicit override;
optimistic concurrency refuses a stale record version. **No monetary
conversion.**

### 11.7 Anomalies (neutral, rule-based)

`attendance_anomalies` are descriptive, rule-based findings — **no AI, no fraud
language, no automatic disciplinary action**. Types: missing checkout, long
session, overlapping sessions, `suspicious_location_change`, lateness streak,
excessive corrections. Each rule is gated by a tenant threshold (null = off) and
carries a stable `dedupe_key` (idempotent). Findings transition
open → acknowledged → resolved/dismissed by human review only.

### 11.8 Corrections & reports

- Corrections now capture the record's `version` at request time and refuse a
  **stale** approval (optimistic concurrency).
- Advanced reports add neutral **compliance rates** (attendance & punctuality —
  never a "performance score"), a full status breakdown, the calculated-vs-
  approved overtime rollup, and a per-employee rollup — **no raw GPS** in any
  report.
