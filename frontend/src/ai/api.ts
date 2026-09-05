import { api, ensureCsrf } from '../lib/api'

/**
 * Sprint 9 — typed client for the read-only AI assistant. The backend gathers
 * only data the caller is already authorized to see and never mutates state.
 */
export type AiFeature =
  | 'dashboard_summary'
  | 'attendance_insights'
  | 'task_workload'
  | 'report_explanation'

export interface AiAvailability {
  available: boolean
  reason: string | null
}

export interface AiInsight {
  feature: string
  available: boolean
  summary: string | null
  highlights: string[]
  unavailable_reason: string | null
}

export const ai = {
  async availability(): Promise<AiAvailability> {
    const { data } = await api.get('/ai/availability')
    return data.data as AiAvailability
  },

  async insight(feature: AiFeature, report?: string): Promise<AiInsight> {
    await ensureCsrf()
    const { data } = await api.post('/ai/insights', { feature, report })
    return data.data as AiInsight
  },
}
