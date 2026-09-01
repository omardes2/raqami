import { api } from '../lib/api'
import type { Option } from './types'

/** Fetch a simple option list from an org endpoint (id + name/title). */
export async function fetchOptions(endpoint: string, labelKey = 'name'): Promise<Option[]> {
  const { data } = await api.get(`/${endpoint}`, { params: { per_page: 100, status: 'active' } })
  const rows = (data.data ?? []) as Record<string, string>[]
  return rows.map((r) => ({ value: r.id, label: r[labelKey] ?? r.name ?? r.title ?? r.code }))
}
