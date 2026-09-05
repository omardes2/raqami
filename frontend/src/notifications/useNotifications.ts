import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { notifications } from './api'

const CHANGED_EVENT = 'notifications:changed'
const POLL_MS = 60_000

/** Broadcast that notification state changed so any mounted count owner refetches. */
export function emitNotificationsChanged(): void {
  window.dispatchEvent(new Event(CHANGED_EVENT))
}

/**
 * Single-owner unread-count source for the topbar bell. Polls unread-count only
 * (never the full list) every 60s while the tab is visible, refetches when the
 * tab becomes visible again, and refetches on a `notifications:changed` event
 * (emitted after mark-read / mark-all anywhere). Resets and refetches when the
 * active tenant changes; stops entirely when unauthenticated. A failed poll
 * keeps the last known count (no logout, no crash).
 */
export function useUnreadCount(): { count: number; refetch: () => Promise<void> } {
  const { user } = useAuth()
  const authed = !!user
  const tenantId = user?.active_tenant?.id ?? null
  const [count, setCount] = useState(0)

  const refetch = useCallback(async () => {
    try {
      setCount(await notifications.unreadCount())
    } catch {
      // Keep the last known count; the next poll retries.
    }
  }, [])

  /* eslint-disable react/set-state-in-effect -- reset count on auth/tenant change is intentional */
  useEffect(() => {
    if (!authed) {
      setCount(0)
      return
    }
    // Reset on (re)auth / tenant switch so a prior tenant's count never shows.
    setCount(0)
    void refetch()

    const interval = window.setInterval(() => {
      if (!document.hidden) void refetch()
    }, POLL_MS)
    const onVisible = () => {
      if (!document.hidden) void refetch()
    }
    const onChanged = () => void refetch()
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener(CHANGED_EVENT, onChanged)

    return () => {
      window.clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener(CHANGED_EVENT, onChanged)
    }
  }, [authed, tenantId, refetch])
  /* eslint-enable react/set-state-in-effect */

  return { count, refetch }
}
