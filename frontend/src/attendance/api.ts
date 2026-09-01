import { api, ensureCsrf } from '../lib/api'

// --- Types (mirror the backend attendance resources) ---

export interface AttendanceRecord {
  id: string
  employee_id: string
  work_schedule_id: string | null
  work_date: string | null
  timezone: string
  scheduled_start_at: string | null
  scheduled_end_at: string | null
  check_in_at: string | null
  check_out_at: string | null
  worked_minutes: number
  break_minutes: number
  late_minutes: number
  early_leave_minutes: number
  overtime_minutes: number
  grace_minutes: number
  status: string
  source: string
  is_manual: boolean
  corrected_at: string | null
  check_in_inside_geofence: boolean | null
  check_out_inside_geofence: boolean | null
  location?: {
    check_in_latitude: number | null
    check_in_longitude: number | null
    check_out_latitude: number | null
    check_out_longitude: number | null
  }
}

export interface AttendanceSettings {
  default_timezone: string
  default_grace_minutes: number
  geofence_required: boolean
  require_gps: boolean
  min_gps_accuracy_meters: number | null
  allow_early_check_in: boolean
  early_check_in_window_minutes: number
  allow_late_check_in: boolean
  overtime_tracking_enabled: boolean
  overtime_after_minutes: number
  attendance_correction_enabled: boolean
  allow_employee_correction_request: boolean
  allow_unscheduled_work: boolean
  // Sprint 4
  materialization_enabled?: boolean
  absence_materialize_after_minutes?: number
  allow_multiple_sessions?: boolean
  auto_close_missing_checkout?: boolean
  overtime_requires_approval?: boolean
  overtime_auto_approve?: boolean
  off_day_work_policy?: string
  default_attendance_mode?: string
  anomaly_max_session_minutes?: number | null
  anomaly_gps_jump_meters?: number | null
  anomaly_lateness_streak_days?: number | null
  anomaly_corrections_threshold?: number | null
}

export interface WorkScheduleSegment {
  sequence: number
  start_time: string
  end_time: string
  break_minutes: number | null
  grace_minutes: number | null
  overtime_after_minutes: number | null
}

export interface WorkScheduleDay {
  weekday: number
  is_working_day: boolean
  start_time: string | null
  end_time: string | null
  break_minutes: number | null
  grace_minutes: number | null
  segments?: WorkScheduleSegment[]
}

export interface WorkScheduleAssignment {
  id: string
  scope_type: string
  scope_id: string | null
  effective_from: string
  effective_until: string | null
  priority: number
}

export interface WorkSchedule {
  id: string
  name: string
  code: string
  timezone: string
  status: string
  description: string | null
  grace_minutes: number
  break_minutes: number
  overtime_after_minutes: number
  cycle_length_days: number | null
  anchor_date: string | null
  is_cyclic: boolean
  days?: WorkScheduleDay[]
  assignments?: WorkScheduleAssignment[]
}

export interface HolidayItem {
  id: string
  holiday_calendar_id: string
  name: string
  date: string | null
  end_date: string | null
  type: string
  is_paid: boolean | null
}

export interface HolidayCalendarAssignment {
  id: string
  scope_type: string
  scope_id: string | null
  effective_from: string | null
  effective_until: string | null
}

export interface HolidayCalendar {
  id: string
  name: string
  code: string
  description: string | null
  holidays?: HolidayItem[]
  assignments?: HolidayCalendarAssignment[]
  created_at: string | null
}

export interface AttendanceException {
  id: string
  employee_id: string
  type: string
  effective_from: string | null
  effective_until: string | null
  attendance_mode: string | null
  alternate_schedule_id: string | null
  alternate_location_id: string | null
  reason: string
  status: string
  created_at: string | null
}

export interface OvertimeApproval {
  id: string
  attendance_record_id: string
  employee_id: string
  work_date: string | null
  calculated_minutes: number
  approved_minutes: number | null
  status: string
  reviewed_by_user_id: string | null
  reviewed_at: string | null
  notes: string | null
  created_at: string | null
}

export interface AttendanceAnomaly {
  id: string
  employee_id: string
  attendance_record_id: string | null
  attendance_session_id: string | null
  type: string
  severity: string
  detected_at: string | null
  status: string
  metadata: Record<string, unknown> | null
  resolution_note: string | null
  created_at: string | null
}

export interface ComplianceReport {
  present: number
  late: number
  absent: number
  scheduled_days: number
  attendance_rate: number | null
  punctuality_rate: number | null
}

export interface OvertimeReport {
  requests: number
  pending: number
  approved: number
  rejected: number
  calculated_minutes: number
  approved_minutes: number
}

export interface EmployeeRollup {
  employee_id: string
  records: number
  present: number
  late: number
  absent: number
  worked_minutes: number
  late_minutes: number
  overtime_minutes: number
}

export interface AdvancedReport {
  compliance: ComplianceReport
  status_breakdown: Record<string, number>
  overtime: OvertimeReport
}

export interface AttendanceLocation {
  id: string
  branch_id: string | null
  name: string
  latitude: number
  longitude: number
  radius_meters: number
  require_accuracy_meters: number | null
  status: string
  description: string | null
}

export interface AttendanceCorrection {
  id: string
  attendance_record_id: string
  employee_id: string
  requested_by_user_id: string
  requested_check_in_at: string | null
  requested_check_out_at: string | null
  reason: string
  status: string
  reviewed_by_user_id: string | null
  reviewed_at: string | null
  rejection_reason: string | null
  created_at: string | null
}

export interface AttendanceSummary {
  records: number
  present: number
  late: number
  absent: number
  worked_minutes: number
  late_minutes: number
  overtime_minutes: number
  early_leave_minutes: number
}

export interface Coordinates {
  latitude: number
  longitude: number
  accuracy_meters?: number
}

/** Read the browser geolocation once, or resolve null if unavailable/denied. */
export function readGeolocation(): Promise<Coordinates | null> {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) {
      resolve(null)
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) =>
        resolve({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy_meters: Math.round(pos.coords.accuracy),
        }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 },
    )
  })
}

function unwrap<T>(data: { data?: T } | T): T {
  return (data as { data?: T }).data ?? (data as T)
}

export const attendance = {
  // --- Employee self-service ---
  async checkIn(payload: Record<string, unknown>): Promise<AttendanceRecord> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/check-in', payload)
    return data
  },
  async checkOut(payload: Record<string, unknown>): Promise<AttendanceRecord> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/check-out', payload)
    return data
  },
  async today(): Promise<{ open: AttendanceRecord | null }> {
    const { data } = await api.get('/attendance/me/today')
    return data
  },
  async myRecords(params: Record<string, unknown> = {}): Promise<AttendanceRecord[]> {
    const { data } = await api.get('/attendance/me', { params })
    return unwrap<AttendanceRecord[]>(data)
  },
  async requestCorrection(recordId: string, payload: Record<string, unknown>): Promise<AttendanceCorrection> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/me/records/${recordId}/corrections`, payload)
    return data
  },

  // --- Admin / HR ---
  async records(params: Record<string, unknown> = {}): Promise<AttendanceRecord[]> {
    const { data } = await api.get('/attendance/records', { params })
    return unwrap<AttendanceRecord[]>(data)
  },
  async summary(params: Record<string, unknown> = {}): Promise<AttendanceSummary> {
    const { data } = await api.get('/attendance/reports/summary', { params })
    return data.summary
  },
  async manual(payload: Record<string, unknown>): Promise<AttendanceRecord> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/records/manual', payload)
    return data
  },

  async settings(): Promise<AttendanceSettings> {
    const { data } = await api.get('/attendance/settings')
    return data
  },
  async updateSettings(payload: Partial<AttendanceSettings>): Promise<AttendanceSettings> {
    await ensureCsrf()
    const { data } = await api.put('/attendance/settings', payload)
    return data
  },

  async schedules(): Promise<WorkSchedule[]> {
    const { data } = await api.get('/attendance/schedules')
    return unwrap<WorkSchedule[]>(data)
  },
  async createSchedule(payload: Record<string, unknown>): Promise<WorkSchedule> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/schedules', payload)
    return data
  },
  async assignSchedule(scheduleId: string, payload: Record<string, unknown>): Promise<void> {
    await ensureCsrf()
    await api.post(`/attendance/schedules/${scheduleId}/assignments`, payload)
  },

  async locations(): Promise<AttendanceLocation[]> {
    const { data } = await api.get('/attendance/locations')
    return unwrap<AttendanceLocation[]>(data)
  },
  async createLocation(payload: Record<string, unknown>): Promise<AttendanceLocation> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/locations', payload)
    return data
  },

  async corrections(params: Record<string, unknown> = {}): Promise<AttendanceCorrection[]> {
    const { data } = await api.get('/attendance/corrections', { params })
    return unwrap<AttendanceCorrection[]>(data)
  },
  async approveCorrection(id: string): Promise<AttendanceCorrection> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/corrections/${id}/approve`)
    return data
  },
  async rejectCorrection(id: string, reason: string): Promise<AttendanceCorrection> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/corrections/${id}/reject`, { rejection_reason: reason })
    return data
  },

  // --- Sprint 4: Holidays ---
  async holidayCalendars(): Promise<HolidayCalendar[]> {
    const { data } = await api.get('/attendance/holidays/calendars')
    return unwrap<HolidayCalendar[]>(data)
  },
  async createHolidayCalendar(payload: Record<string, unknown>): Promise<HolidayCalendar> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/holidays/calendars', payload)
    return data
  },
  async addHoliday(calendarId: string, payload: Record<string, unknown>): Promise<HolidayItem> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/holidays/calendars/${calendarId}/holidays`, payload)
    return data
  },
  async deleteHoliday(calendarId: string, holidayId: string): Promise<void> {
    await ensureCsrf()
    await api.delete(`/attendance/holidays/calendars/${calendarId}/holidays/${holidayId}`)
  },
  async assignHolidayCalendar(calendarId: string, payload: Record<string, unknown>): Promise<void> {
    await ensureCsrf()
    await api.post(`/attendance/holidays/calendars/${calendarId}/assignments`, payload)
  },

  // --- Sprint 4: Exceptions ---
  async exceptions(params: Record<string, unknown> = {}): Promise<AttendanceException[]> {
    const { data } = await api.get('/attendance/exceptions', { params })
    return unwrap<AttendanceException[]>(data)
  },
  async createException(payload: Record<string, unknown>): Promise<AttendanceException> {
    await ensureCsrf()
    const { data } = await api.post('/attendance/exceptions', payload)
    return data
  },
  async revokeException(id: string): Promise<AttendanceException> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/exceptions/${id}/revoke`)
    return data
  },

  // --- Sprint 4: Overtime approval ---
  async overtime(params: Record<string, unknown> = {}): Promise<OvertimeApproval[]> {
    const { data } = await api.get('/attendance/overtime', { params })
    return unwrap<OvertimeApproval[]>(data)
  },
  async approveOvertime(id: string, payload: Record<string, unknown> = {}): Promise<OvertimeApproval> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/overtime/${id}/approve`, payload)
    return data
  },
  async rejectOvertime(id: string, notes?: string): Promise<OvertimeApproval> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/overtime/${id}/reject`, { notes })
    return data
  },

  // --- Sprint 4: Anomalies ---
  async anomalies(params: Record<string, unknown> = {}): Promise<AttendanceAnomaly[]> {
    const { data } = await api.get('/attendance/anomalies', { params })
    return unwrap<AttendanceAnomaly[]>(data)
  },
  async reviewAnomaly(id: string, status: string, note?: string): Promise<AttendanceAnomaly> {
    await ensureCsrf()
    const { data } = await api.post(`/attendance/anomalies/${id}/review`, { status, note })
    return data
  },

  // --- Sprint 4: Advanced reports ---
  async advancedReport(params: Record<string, unknown> = {}): Promise<AdvancedReport> {
    const { data } = await api.get('/attendance/reports/advanced', { params })
    return { compliance: data.compliance, status_breakdown: data.status_breakdown, overtime: data.overtime }
  },
  async byEmployeeReport(params: Record<string, unknown> = {}): Promise<EmployeeRollup[]> {
    const { data } = await api.get('/attendance/reports/by-employee', { params })
    return data.employees as EmployeeRollup[]
  },
}

/** Format a minute count as Hh Mm for display. */
export function minutes(total: number): string {
  const h = Math.floor(total / 60)
  const m = total % 60
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}
