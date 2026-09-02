import { api, ensureCsrf } from '../lib/api'

// --- Types (mirror the backend leave resources) ---

export interface LeaveType {
  id: string
  code: string
  name: string
  description: string | null
  category: string
  status: string
  paid_classification: string | null
  requires_attachment: boolean
  attachment_required_after_minutes: number | null
  allow_half_day: boolean
  allow_hourly: boolean
  color: string | null
}

export interface LeavePolicy {
  id: string
  leave_type_id: string
  name: string
  status: string
  effective_from: string | null
  effective_until: string | null
  period_basis: string
  entitlement_method: string
  entitlement_minutes: number
  accrual_frequency: string
  accrual_minutes: number
  proration_enabled: boolean
  max_balance_minutes: number | null
  allow_negative_balance: boolean
  max_negative_minutes: number | null
  carry_forward_enabled: boolean
  carry_forward_max_minutes: number | null
  carry_forward_expiry_days: number | null
  consumption_basis: string
  nominal_day_minutes: number | null
  count_holidays: boolean
  count_non_working_days: boolean
  allow_half_day: boolean
  approval_flow: string
  assignments?: LeavePolicyAssignment[]
}

export interface LeavePolicyAssignment {
  id: string
  scope_type: string
  scope_id: string | null
  effective_from: string | null
  effective_until: string | null
  priority: number
}

export interface LeaveBalance {
  id: string
  employee_id: string
  leave_type_id: string
  entitlement_period_id: string
  granted_minutes: number
  accrued_minutes: number
  carried_minutes: number
  adjusted_minutes: number
  used_minutes: number
  reserved_minutes: number
  expired_minutes: number
  available_minutes: number
}

export interface LeaveRequestDay {
  work_date: string
  scheduled_minutes: number
  coverage_minutes: number
  consumption_minutes: number
  portion: string
  excluded_reason: string | null
}

export interface LeaveApprovalStep {
  step_order: number
  purpose: string
  approver_type: string
  status: string
  reviewed_at: string | null
  note: string | null
}

export interface LeaveRequest {
  id: string
  employee_id: string
  leave_type_id: string
  request_kind: string
  starts_on: string | null
  ends_on: string | null
  requested_consumption_minutes: number
  requested_coverage_minutes: number
  status: string
  reason: string | null
  submitted_at: string | null
  version: number
  days?: LeaveRequestDay[]
  approvals?: LeaveApprovalStep[]
}

export interface LeavePreview {
  available_before: number
  available_after: number
  total_consumption_minutes: number
  total_coverage_minutes: number
  days: Array<Record<string, unknown>>
}

export interface LeaveSettings {
  id: string
  default_period_basis: string
  leave_year_start_month: number
  leave_year_start_day: number
  default_approval_flow: string
  allow_withdrawal: boolean
  allow_cancellation_request: boolean
  display_day_minutes: number
}

function unwrap<T>(data: unknown): T {
  if (data && typeof data === 'object' && 'data' in (data as Record<string, unknown>)) {
    return (data as { data: T }).data
  }
  return data as T
}

export const leave = {
  // --- Employee self-service ---
  async myBalances(): Promise<LeaveBalance[]> {
    const { data } = await api.get('/leave/me/balances')
    return unwrap<LeaveBalance[]>(data)
  },
  async myRequests(params: Record<string, unknown> = {}): Promise<LeaveRequest[]> {
    const { data } = await api.get('/leave/me/requests', { params })
    return unwrap<LeaveRequest[]>(data)
  },
  async preview(payload: Record<string, unknown>): Promise<LeavePreview> {
    await ensureCsrf()
    const { data } = await api.post('/leave/requests/preview', payload)
    return data
  },
  async submit(payload: Record<string, unknown>): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post('/leave/requests', payload)
    return data
  },
  async withdraw(id: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/withdraw`, {})
    return data
  },
  async requestCancellation(id: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/request-cancellation`, {})
    return data
  },

  // --- Management ---
  async requests(params: Record<string, unknown> = {}): Promise<LeaveRequest[]> {
    const { data } = await api.get('/leave/requests', { params })
    return unwrap<LeaveRequest[]>(data)
  },
  async approve(id: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/approve`, {})
    return data
  },
  async reject(id: string, note?: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/reject`, { note })
    return data
  },
  async cancel(id: string, reason: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/cancel`, { reason })
    return data
  },
  async approveCancellation(id: string): Promise<LeaveRequest> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/requests/${id}/cancellation/approve`, {})
    return data
  },
  async balances(params: Record<string, unknown> = {}): Promise<LeaveBalance[]> {
    const { data } = await api.get('/leave/balances', { params })
    return unwrap<LeaveBalance[]>(data)
  },
  async adjust(payload: Record<string, unknown>): Promise<unknown> {
    await ensureCsrf()
    const { data } = await api.post('/leave/balances/adjust', payload)
    return data
  },
  async summary(params: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
    const { data } = await api.get('/leave/reports/summary', { params })
    return unwrap<Record<string, unknown>>(data)
  },
  async calendar(params: Record<string, unknown> = {}): Promise<LeaveRequest[]> {
    const { data } = await api.get('/leave/calendar', { params })
    return unwrap<LeaveRequest[]>(data)
  },

  // --- Admin: types ---
  async types(params: Record<string, unknown> = {}): Promise<LeaveType[]> {
    const { data } = await api.get('/leave/types', { params })
    return unwrap<LeaveType[]>(data)
  },
  async createType(payload: Record<string, unknown>): Promise<LeaveType> {
    await ensureCsrf()
    const { data } = await api.post('/leave/types', payload)
    return data
  },
  async updateType(id: string, payload: Record<string, unknown>): Promise<LeaveType> {
    await ensureCsrf()
    const { data } = await api.put(`/leave/types/${id}`, payload)
    return data
  },
  async archiveType(id: string): Promise<LeaveType> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/types/${id}/archive`, {})
    return data
  },

  // --- Admin: policies ---
  async policies(params: Record<string, unknown> = {}): Promise<LeavePolicy[]> {
    const { data } = await api.get('/leave/policies', { params })
    return unwrap<LeavePolicy[]>(data)
  },
  async createPolicy(payload: Record<string, unknown>): Promise<LeavePolicy> {
    await ensureCsrf()
    const { data } = await api.post('/leave/policies', payload)
    return data
  },
  async assignPolicy(id: string, payload: Record<string, unknown>): Promise<LeavePolicy> {
    await ensureCsrf()
    const { data } = await api.post(`/leave/policies/${id}/assignments`, payload)
    return data
  },

  // --- Admin: settings ---
  async settings(): Promise<LeaveSettings> {
    const { data } = await api.get('/leave/settings')
    return data
  },
  async updateSettings(payload: Partial<LeaveSettings>): Promise<LeaveSettings> {
    await ensureCsrf()
    const { data } = await api.put('/leave/settings', payload)
    return data
  },
}

/** Minutes as "Hh Mm" (canonical unit is minutes; display only). */
export function minutes(total: number): string {
  const sign = total < 0 ? '-' : ''
  const abs = Math.abs(total)
  const h = Math.floor(abs / 60)
  const m = abs % 60
  return h > 0 ? `${sign}${h}h ${m}m` : `${sign}${m}m`
}

/** Minutes as display "days" using the tenant's display-day length. */
export function days(total: number, perDay: number): string {
  if (!perDay) return minutes(total)
  return `${(total / perDay).toFixed(2)}d`
}
