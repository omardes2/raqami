import { api } from '../lib/api'

// --- Types (mirror the Sprint 8A organization report resources) ---

export interface CountBucket {
  key: string | null
  count: number
}

export interface OrgSummary {
  total: number
  active: number
  inactive: number
  by_employment_status: CountBucket[]
  by_branch: CountBucket[]
  by_department: CountBucket[]
  by_team: CountBucket[]
}

export interface TurnoverMonth {
  month: string
  joiners: number
  leavers: number
}

export interface OrgTurnover {
  from: string
  to: string
  source: string
  joiners_total: number
  leavers_total: number
  by_month: TurnoverMonth[]
  data_quality: { missing_hire_date: number }
}

export interface ReportMeta {
  filters: Record<string, unknown>
  generated_at: string
  timezone: string
}

export interface Envelope<T> {
  data: T
  meta: ReportMeta
}

// --- Payroll reports (Sprint 8A Phase 2) ---
// Money is ALWAYS grouped by currency and never combined across currencies;
// every amount is an integer in minor units and rendered with money().

export interface PayrollCurrencyTotals {
  currency: string
  entry_count?: number
  employee_count?: number
  gross_minor: number
  deduction_minor: number
  net_minor: number
}

export interface PayrollSummary {
  entry_count: number
  currencies: string[]
  by_currency: PayrollCurrencyTotals[]
}

export interface PayrollPeriodTotals {
  period_id: string
  label: string | null
  period_start: string | null
  period_end: string | null
  by_currency: PayrollCurrencyTotals[]
}

export interface PayrollComponentTotal {
  currency: string
  direction: string
  line_type: string
  label: string | null
  line_count: number
  amount_minor: number
}

export interface PayrollRunStatusCount {
  status: string
  run_count: number
}

export interface PayrollReportFilters {
  payroll_period_id?: string
  currency?: string
}

// --- Company dashboard (composite; only authorized cards are present) ---

export interface DashboardData {
  organization?: { active_employees: number }
  attendance?: { date: string; present: number; absent: number; on_leave: number }
  leave?: { pending_requests: number }
  tasks?: { overdue: number }
  payroll?: {
    latest_period_label: string | null
    latest_period_status: string | null
    latest_run_status: string | null
  }
}

export const reports = {
  async organizationSummary(): Promise<Envelope<OrgSummary>> {
    const { data } = await api.get('/employees/reports/summary')
    return data as Envelope<OrgSummary>
  },
  async organizationTurnover(params: { from?: string; to?: string } = {}): Promise<Envelope<OrgTurnover>> {
    const { data } = await api.get('/employees/reports/turnover', { params })
    return data as Envelope<OrgTurnover>
  },
  async payrollSummary(params: PayrollReportFilters = {}): Promise<Envelope<PayrollSummary>> {
    const { data } = await api.get('/payroll/reports/summary', { params })
    return data as Envelope<PayrollSummary>
  },
  async payrollByPeriod(params: PayrollReportFilters = {}): Promise<Envelope<PayrollPeriodTotals[]>> {
    const { data } = await api.get('/payroll/reports/by-period', { params })
    return data as Envelope<PayrollPeriodTotals[]>
  },
  async payrollComponents(params: PayrollReportFilters = {}): Promise<Envelope<PayrollComponentTotal[]>> {
    const { data } = await api.get('/payroll/reports/components', { params })
    return data as Envelope<PayrollComponentTotal[]>
  },
  async payrollRunStatus(): Promise<Envelope<PayrollRunStatusCount[]>> {
    const { data } = await api.get('/payroll/reports/run-status')
    return data as Envelope<PayrollRunStatusCount[]>
  },
  async companyDashboard(): Promise<Envelope<DashboardData>> {
    const { data } = await api.get('/dashboard/company')
    return data as Envelope<DashboardData>
  },
}
