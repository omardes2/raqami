import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { notifications, type NotificationItem } from '../../notifications/api'
import { emitNotificationsChanged } from '../../notifications/useNotifications'
import { notificationAction, notificationTitle, relativeTime } from '../../notifications/format'

/**
 * Full notification inbox (Sprint 8B Phase 2). All/Unread filter, paginated,
 * newest-first, with mark-one and mark-all. Read-only ownership is enforced by
 * the backend + RLS; the client never filters by tenant/recipient. No delete.
 */
export default function NotificationsPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [rows, setRows] = useState<NotificationItem[]>([])
  const [meta, setMeta] = useState<{ current_page: number; last_page: number }>({ current_page: 1, last_page: 1 })
  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [hasUnread, setHasUnread] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(false)
    try {
      const res = await notifications.list({ page, per_page: 20, unread: unreadOnly })
      setRows(res.data)
      setMeta(res.meta)
      // Track whether a "mark all" action is meaningful without a separate call
      // when already viewing unread; otherwise rely on the presence of unread rows.
      setHasUnread(res.data.some((r) => r.read_at === null) || (await notifications.unreadCount()) > 0)
    } catch {
      setError(true)
    } finally {
      setLoading(false)
    }
  }, [page, unreadOnly])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function switchFilter(next: boolean) {
    setUnreadOnly(next)
    setPage(1) // never leave a page number that won't exist in the filtered result
  }

  async function openItem(item: NotificationItem) {
    if (item.read_at === null) {
      try {
        await notifications.markRead(item.id)
        emitNotificationsChanged()
        await load()
      } catch {
        setError(true)
      }
    }
    // Follow the whitelisted deep-link, if any; the target re-authorizes.
    const to = notificationAction(item)
    if (to) navigate(to)
  }

  async function markAll() {
    try {
      await notifications.markAllRead()
      emitNotificationsChanged()
      await load()
    } catch {
      setError(true)
    }
  }

  return (
    <div className="page">
      <h1>{t('notifications.title')}</h1>

      <div className="filters">
        <div className="notif-tabs" role="tablist">
          <button type="button" role="tab" aria-selected={!unreadOnly} className={!unreadOnly ? 'btn-ghost is-active' : 'btn-ghost'} onClick={() => switchFilter(false)}>
            {t('notifications.all')}
          </button>
          <button type="button" role="tab" aria-selected={unreadOnly} className={unreadOnly ? 'btn-ghost is-active' : 'btn-ghost'} onClick={() => switchFilter(true)}>
            {t('notifications.unread')}
          </button>
        </div>
        {hasUnread && (
          <button type="button" className="btn-link" onClick={markAll}>{t('notifications.mark_all_read')}</button>
        )}
      </div>

      {error && <p className="error" role="alert">{t('notifications.error')}</p>}

      {loading ? (
        <p className="muted" role="status">{t('notifications.loading')}</p>
      ) : rows.length === 0 ? (
        <p className="muted">{unreadOnly ? t('notifications.empty_unread') : t('notifications.empty')}</p>
      ) : (
        <section className="card">
          <ul className="notif-list notif-list-page">
            {rows.map((item) => (
              <li key={item.id} className={item.read_at ? 'notif-item' : 'notif-item is-unread'}>
                <button type="button" className="notif-item-btn" onClick={() => openItem(item)}>
                  {item.read_at === null && <span className="notif-dot" aria-hidden="true" />}
                  <span className="notif-item-title">{notificationTitle(t, item)}</span>
                  <span className="notif-item-time muted">{relativeTime(item.created_at, i18n.language)}</span>
                </button>
              </li>
            ))}
          </ul>
          {meta.last_page > 1 && (
            <div className="pager">
              <button className="btn-ghost" disabled={meta.current_page <= 1} onClick={() => setPage((p) => p - 1)}>‹</button>
              <span>{meta.current_page} / {meta.last_page}</span>
              <button className="btn-ghost" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>›</button>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
