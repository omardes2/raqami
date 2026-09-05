import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { notifications, type NotificationItem } from '../notifications/api'
import { emitNotificationsChanged, useUnreadCount } from '../notifications/useNotifications'
import { notificationAction, notificationTitle, relativeTime, unreadBadge } from '../notifications/format'

const PREVIEW_SIZE = 5

/**
 * Topbar notification bell: unread badge (authoritative unread-count endpoint,
 * never derived from a loaded page), a preview dropdown of the latest few, and
 * mark-one / mark-all actions. It is the single polling owner for the unread
 * count. Closes on outside click / Escape. No permission required — a user sees
 * only their own notifications (enforced by the backend + RLS).
 */
export default function NotificationBell() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { count, refetch } = useUnreadCount()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<NotificationItem[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  const loadPreview = useCallback(async () => {
    setLoading(true)
    setError(false)
    try {
      const res = await notifications.list({ per_page: PREVIEW_SIZE })
      setItems(res.data)
    } catch {
      setError(true)
    } finally {
      setLoading(false)
    }
  }, [])

  const openDropdown = useCallback(() => {
    setOpen(true)
    void refetch()
    void loadPreview()
  }, [refetch, loadPreview])

  useEffect(() => {
    if (!open) return
    const onClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onClick)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  async function openItem(item: NotificationItem) {
    if (item.read_at === null) {
      try {
        await notifications.markRead(item.id)
        emitNotificationsChanged()
        await Promise.all([refetch(), loadPreview()])
      } catch {
        setError(true)
      }
    }
    const to = notificationAction(item)
    if (to) {
      setOpen(false)
      navigate(to)
    }
  }

  async function markAll() {
    try {
      await notifications.markAllRead()
      emitNotificationsChanged()
      await Promise.all([refetch(), loadPreview()])
    } catch {
      setError(true)
    }
  }

  const badge = unreadBadge(count)

  return (
    <div className="notif" ref={containerRef}>
      <button
        type="button"
        className="notif-bell"
        aria-label={t('notifications.title')}
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => (open ? setOpen(false) : openDropdown())}
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
          <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M13.7 21a2 2 0 0 1-3.4 0" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        {badge !== '' && (
          <span className="notif-badge" aria-label={t('notifications.unread_count', { count })}>{badge}</span>
        )}
      </button>

      {open && (
        <div className="notif-panel" role="menu">
          <div className="notif-panel-head">
            <strong>{t('notifications.title')}</strong>
            {count > 0 && (
              <button type="button" className="btn-link" onClick={markAll}>{t('notifications.mark_all_read')}</button>
            )}
          </div>

          {loading ? (
            <p className="muted notif-msg" role="status">{t('notifications.loading')}</p>
          ) : error ? (
            <p className="error notif-msg" role="alert">{t('notifications.error')}</p>
          ) : items.length === 0 ? (
            <p className="muted notif-msg">{t('notifications.empty')}</p>
          ) : (
            <ul className="notif-list">
              {items.map((item) => (
                <li key={item.id} className={item.read_at ? 'notif-item' : 'notif-item is-unread'}>
                  <button type="button" className="notif-item-btn" onClick={() => openItem(item)}>
                    {item.read_at === null && <span className="notif-dot" aria-hidden="true" />}
                    <span className="notif-item-title">{notificationTitle(t, item)}</span>
                    <span className="notif-item-time muted">{relativeTime(item.created_at, i18n.language)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          <div className="notif-panel-foot">
            <button
              type="button"
              className="btn-link"
              onClick={() => {
                setOpen(false)
                navigate('/notifications')
              }}
            >
              {t('notifications.view_all')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
