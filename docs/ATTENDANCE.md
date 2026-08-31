# Attendance Engine — Raqmi Dawam

**Status:** Design (planning phase). Covers check-in/out, configurable working
days & hours, shifts, grace periods, overtime, GPS attendance, and geofencing.

---

## 1. Goals

- Accurate, flexible attendance for diverse companies and shift patterns.
- Configurable at company, branch, department, and employee levels.
- Location-aware (GPS + geofencing) with clear enforcement modes.
- Feed clean, auditable data into leave, reports, and payroll.
- Work in both web and (future) mobile via the API.

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

## 10. Open Questions

- Overtime policy defaults and legal rounding rules per region.
- Kiosk/biometric device support in scope? (See `DECISIONS.md`.)
