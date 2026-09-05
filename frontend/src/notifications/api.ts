import { api, ensureCsrf } from '../lib/api'

/**
 * Typed client for the Sprint 8B personal notification inbox. Mirrors the
 * backend NotificationResource EXACTLY — no hidden ownership fields
 * (tenant_id / recipient_user_id / dedupe_key) exist client-side; the backend
 * + RLS remain authoritative. All reads are the caller's own rows only.
 */
export interface NotificationItem {
  id: string
  type: string
  title_key: string | null
  params: Record<string, string | number | boolean | null>
  subject_type: string | null
  subject_id: string | null
  read_at: string | null
  created_at: string
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface ListParams {
  page?: number
  per_page?: number
  unread?: boolean
}

export const notifications = {
  async list(params: ListParams = {}): Promise<Paginated<NotificationItem>> {
    const { data } = await api.get('/me/notifications', {
      params: {
        page: params.page,
        per_page: params.per_page,
        unread: params.unread ? 1 : undefined,
      },
    })
    return data as Paginated<NotificationItem>
  },

  async unreadCount(): Promise<number> {
    const { data } = await api.get('/me/notifications/unread-count')
    return Number(data?.data?.unread_count ?? 0)
  },

  async markRead(id: string): Promise<void> {
    await ensureCsrf()
    await api.patch(`/me/notifications/${id}/read`)
  },

  async markAllRead(): Promise<number> {
    await ensureCsrf()
    const { data } = await api.post('/me/notifications/read-all')
    return Number(data?.data?.updated ?? 0)
  },
}
