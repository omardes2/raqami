import type { TFunction } from 'i18next'
import type { NotificationItem } from './api'

/** Badge text: hidden at 0, exact 1–99, "99+" above. Centralized (bell + page). */
export function unreadBadge(count: number): string {
  if (count <= 0) return ''
  return count > 99 ? '99+' : String(count)
}

/**
 * Localized title for a notification. The backend persists a stable translation
 * key + safe params; the frontend translates. An unknown key (a row that
 * outlived a deployment) falls back to a generic localized label — never the raw
 * key. Params are passed as i18next interpolation values (React/i18next escape
 * them); they are never treated as HTML or URLs.
 */
export function notificationTitle(t: TFunction, item: NotificationItem): string {
  const params = item.params ?? {}
  if (item.title_key) {
    return t(item.title_key, { ...params, defaultValue: t('notifications.generic') })
  }
  return t('notifications.generic')
}

/** Relative time using the built-in Intl API (no dependency); ISO input. */
export function relativeTime(iso: string, locale: string): string {
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return ''
  const diffMs = then - Date.now()
  const abs = Math.abs(diffMs)
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 31536000000],
    ['month', 2592000000],
    ['day', 86400000],
    ['hour', 3600000],
    ['minute', 60000],
  ]
  for (const [unit, ms] of units) {
    if (abs >= ms) return rtf.format(Math.round(diffMs / ms), unit)
  }
  return rtf.format(Math.round(diffMs / 1000), 'second')
}

/**
 * Deep-link resolver: maps ONLY known, verified notification types to in-app
 * routes. Phase 2 has no live domain producers, so it returns null for all
 * types (no unsafe URL is ever built from subject_type). Phase 3/4 will add
 * explicit mappings as the event catalog becomes real. A resolved link is
 * navigation only — the target page/API performs its own authorization.
 */
export function notificationAction(_item: NotificationItem): string | null {
  return null
}
