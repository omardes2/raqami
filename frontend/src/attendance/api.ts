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
}

export interface WorkScheduleDay {
  weekday: number
  is_working_day: boolean
  start_time: string | null
  end_time: string | null
  break_minutes: number | null
  grace_minutes: number | null
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
  days?: WorkScheduleDay[]
  assignments?: WorkScheduleAssignment[]
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
}

/** Format a minute count as Hh Mm for display. */
export function minutes(total: number): string {
  const h = Math.floor(total / 60)
  const m = total % 60
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}
