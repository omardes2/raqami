import { api, ensureCsrf } from '../lib/api'

// --- Types (mirror the backend payroll resources) ---

export interface PayrollSettings {
  id: string
  payroll_timezone: string
  overtime_pay_enabled: boolean
  require_four_eyes: boolean
  allow_self_payroll: boolean
  version: number
}

export interface PayrollComponent {
  id: string
  code: string
  name: string
  type: string // earning | deduction
  calculation_mode: string // fixed | percent_of_base
  active: boolean
  sort_order: number
}

export interface EmployeeCompensation {
  id: string
  employee_id: string
  currency: string
  base_amount_minor: number
  overtime_rate_minor_per_hour: number | null
  effective_from: string | null
  effective_to: string | null
  version: number
}

export interface EmployeeComponentAssignment {
  id: string
  employee_id: string
  payroll_component_id: string
  fixed_amount_minor: number | null
  rate_bps: number | null
  currency: string | null
  effective_from: string | null
  effective_to: string | null
  version: number
}

export interface PayrollPeriod {
  id: string
  label: string
  period_start: string | null
  period_end: string | null
  timezone: string
  status: string // open | closed
}

export interface PayrollRun {
  id: string
  payroll_period_id: string
  status: string
  calculation_version: string | null
  calculated_at: string | null
  approved_at: string | null
  finalized_at: string | null
  cancelled_at: string | null
  version: number
}

export interface PayrollEntryLine {
  id: string
  line_code: string
  line_type: string
  direction: string
  source_type: string
  source_id: string | null
  label: string
  quantity_minutes: number | null
  rate_minor_per_hour: number | null
  rate_bps: number | null
  amount_minor: number
  metadata: Record<string, unknown> | null
  sort_order: number
}

export interface PayrollEntry {
  id: string
  employee_id: string
  employee: { employee_number: string | null; name: string | null; job_title: string | null }
  status: string
  currency: string | null
  gross_minor: number | null
  deduction_minor: number | null
  net_minor: number | null
  negative_net: boolean
  error_code: string | null
  calculation_version: string | null
  calculated_at: string | null
  lines?: PayrollEntryLine[]
}

export interface PayrollAdjustment {
  id: string
  payroll_period_id: string
  employee_id: string
  employee_visible_label: string
  direction: string // earning | deduction
  amount_minor: number
  currency: string
  internal_reason: string
  source_payroll_entry_id: string | null
  version: number
  created_at: string | null
}

export interface OwnPayslip {
  id: string
  payroll_period_id: string
  period_label: string | null
  period_start: string | null
  period_end: string | null
  currency: string
  gross_minor: number
  deduction_minor: number
  net_minor: number
  finalized_at: string | null
  employee_number: string | null
  employee_name: string | null
}

export interface OwnPayslipLine {
  line_type: string
  label: string
  quantity_minutes: number | null
  rate_minor_per_hour: number | null
  amount_minor: number
}

export interface OwnPayslipDetail {
  id: string
  currency: string
  gross_minor: number
  deduction_minor: number
  net_minor: number
  finalized_at: string | null
  period: { id: string | null; label: string | null; start: string | null; end: string | null }
  employee: { employee_number: string | null; name: string | null; job_title: string | null }
  company: { name: string | null }
  earnings: OwnPayslipLine[]
  deductions: OwnPayslipLine[]
}

export interface RunCurrencyGroup {
  currency: string
  gross_minor: number
  deduction_minor: number
  net_minor: number
  employee_count: number
}

export interface RunSummary {
  by_currency: RunCurrencyGroup[]
  counts: { cohort: number; calculated: number; failed: number; pending: number }
}

// $wrap=null resources return a bare object for one item; collections may be a
// bare array or a { data } envelope depending on the endpoint — handle both.
const one = <T>(d: { data?: T } | T): T => (d && typeof d === 'object' && 'data' in (d as object) ? (d as { data: T }).data : (d as T))
const many = <T>(d: unknown): T[] => (Array.isArray(d) ? (d as T[]) : (((d as { data?: T[] })?.data) ?? []))

export const payroll = {
  async settings(): Promise<PayrollSettings> {
    const { data } = await api.get('/payroll/settings')
    return one<PayrollSettings>(data)
  },
  async updateSettings(payload: Partial<PayrollSettings>): Promise<PayrollSettings> {
    await ensureCsrf()
    const { data } = await api.patch('/payroll/settings', payload)
    return one<PayrollSettings>(data)
  },
  async components(): Promise<PayrollComponent[]> {
    const { data } = await api.get('/payroll/components')
    return many<PayrollComponent>(data)
  },
  async createComponent(payload: Record<string, unknown>): Promise<PayrollComponent> {
    await ensureCsrf()
    const { data } = await api.post('/payroll/components', payload)
    return one<PayrollComponent>(data)
  },
  async updateComponent(id: string, payload: Record<string, unknown>): Promise<PayrollComponent> {
    await ensureCsrf()
    const { data } = await api.patch(`/payroll/components/${id}`, payload)
    return one<PayrollComponent>(data)
  },
  async compensations(employeeId: string): Promise<EmployeeCompensation[]> {
    const { data } = await api.get(`/payroll/compensations/${employeeId}`)
    return many<EmployeeCompensation>(data)
  },
  async createCompensation(employeeId: string, payload: Record<string, unknown>): Promise<EmployeeCompensation> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/compensations/${employeeId}`, payload)
    return one<EmployeeCompensation>(data)
  },
  async endCompensation(compensationId: string, effectiveTo: string): Promise<EmployeeCompensation> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/compensations/${compensationId}/end`, { effective_to: effectiveTo })
    return one<EmployeeCompensation>(data)
  },
  async employeeComponents(employeeId: string): Promise<EmployeeComponentAssignment[]> {
    const { data } = await api.get(`/payroll/employees/${employeeId}/components`)
    return many<EmployeeComponentAssignment>(data)
  },
  async assignComponent(employeeId: string, payload: Record<string, unknown>): Promise<EmployeeComponentAssignment> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/employees/${employeeId}/components`, payload)
    return one<EmployeeComponentAssignment>(data)
  },
  async endComponentAssignment(assignmentId: string, effectiveTo: string): Promise<EmployeeComponentAssignment> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/employee-components/${assignmentId}/end`, { effective_to: effectiveTo })
    return one<EmployeeComponentAssignment>(data)
  },
  async periods(): Promise<PayrollPeriod[]> {
    const { data } = await api.get('/payroll/periods')
    return many<PayrollPeriod>(data)
  },
  async createPeriod(payload: Record<string, unknown>): Promise<PayrollPeriod> {
    await ensureCsrf()
    const { data } = await api.post('/payroll/periods', payload)
    return one<PayrollPeriod>(data)
  },
  async runs(params: Record<string, unknown> = {}): Promise<PayrollRun[]> {
    const { data } = await api.get('/payroll/runs', { params })
    return many<PayrollRun>(data)
  },
  async run(id: string): Promise<PayrollRun> {
    const { data } = await api.get(`/payroll/runs/${id}`)
    return one<PayrollRun>(data)
  },
  async calculateRun(id: string): Promise<PayrollRun> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/runs/${id}/calculate`, {})
    return one<PayrollRun>(data)
  },
  async recalculateRun(id: string): Promise<PayrollRun> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/runs/${id}/recalculate`, {})
    return one<PayrollRun>(data)
  },
  async runEntries(id: string): Promise<PayrollEntry[]> {
    const { data } = await api.get(`/payroll/runs/${id}/entries`)
    return many<PayrollEntry>(data)
  },
  async runSummary(id: string): Promise<RunSummary> {
    const { data } = await api.get(`/payroll/runs/${id}/summary`)
    return data as RunSummary
  },
  async entry(entryId: string): Promise<PayrollEntry> {
    const { data } = await api.get(`/payroll/entries/${entryId}`)
    return one<PayrollEntry>(data)
  },
  async createRun(periodId: string): Promise<PayrollRun> {
    await ensureCsrf()
    const { data } = await api.post('/payroll/runs', { payroll_period_id: periodId })
    return one<PayrollRun>(data)
  },
  async cancelRun(id: string): Promise<PayrollRun> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/runs/${id}/cancel`, {})
    return one<PayrollRun>(data)
  },
  // --- Phase 2B: adjustments (period-owned), approval, finalization ---
  async periodAdjustments(periodId: string): Promise<PayrollAdjustment[]> {
    const { data } = await api.get(`/payroll/periods/${periodId}/adjustments`)
    return many<PayrollAdjustment>(data)
  },
  async createAdjustment(periodId: string, payload: Record<string, unknown>): Promise<PayrollAdjustment> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/periods/${periodId}/adjustments`, payload)
    return one<PayrollAdjustment>(data)
  },
  async updateAdjustment(adjustmentId: string, payload: Record<string, unknown>): Promise<PayrollAdjustment> {
    await ensureCsrf()
    const { data } = await api.patch(`/payroll/adjustments/${adjustmentId}`, payload)
    return one<PayrollAdjustment>(data)
  },
  async deleteAdjustment(adjustmentId: string): Promise<void> {
    await ensureCsrf()
    await api.delete(`/payroll/adjustments/${adjustmentId}`)
  },
  async approveRun(id: string): Promise<PayrollRun> {
    await ensureCsrf()
    const { data } = await api.post(`/payroll/runs/${id}/approve`, {})
    return one<PayrollRun>(data)
  },
  async finalizeRun(id: string, negativeNetReason?: string): Promise<PayrollRun> {
    await ensureCsrf()
    const payload = negativeNetReason ? { negative_net_override_reason: negativeNetReason } : {}
    const { data } = await api.post(`/payroll/runs/${id}/finalize`, payload)
    return one<PayrollRun>(data)
  },
  // --- Employee self-service: finalized payslips (read-only) ---
  async myPayslips(): Promise<OwnPayslip[]> {
    const { data } = await api.get('/payroll/me/payslips')
    return many<OwnPayslip>(data)
  },
  async myPayslip(entryId: string): Promise<OwnPayslipDetail> {
    const { data } = await api.get(`/payroll/me/payslips/${entryId}`)
    return one<OwnPayslipDetail>(data)
  },
}
