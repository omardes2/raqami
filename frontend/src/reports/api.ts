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

export const reports = {
  async organizationSummary(): Promise<Envelope<OrgSummary>> {
    const { data } = await api.get('/employees/reports/summary')
    return data as Envelope<OrgSummary>
  },
  async organizationTurnover(params: { from?: string; to?: string } = {}): Promise<Envelope<OrgTurnover>> {
    const { data } = await api.get('/employees/reports/turnover', { params })
    return data as Envelope<OrgTurnover>
  },
}
