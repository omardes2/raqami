import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, type LeaveRequest } from '../../leave/api'

/**
 * Team leave calendar (scoped). Privacy: shows only employee, type, dates and
 * status — never reason, medical detail, or attachments.
 */
export default function LeaveCalendar() {
  const { t } = useTranslation()
  const [items, setItems] = useState<LeaveRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setItems(await leave.calendar())
    } catch (e) {
      setError((e as Error).message)
    }
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.calendar')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <table>
        <thead>
          <tr>
            <th>{t('leave.employee')}</th>
            <th>{t('leave.type')}</th>
            <th>{t('leave.dates')}</th>
            <th>{t('leave.status')}</th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={4}>{t('leave.no_upcoming')}</td></tr>}
          {items.map((r) => (
            <tr key={r.id}>
              <td>{r.employee_id}</td>
              <td>{r.leave_type_id}</td>
              <td>{r.starts_on} → {r.ends_on}</td>
              <td>{t(`leave.status_${r.status}`)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
