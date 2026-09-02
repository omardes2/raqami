import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, minutes } from '../../leave/api'

interface SummaryRow { leave_type_id: string; requests: number; consumption_minutes: number }
interface Summary { from: string; to: string; by_type: SummaryRow[]; total_consumption_minutes: number }

/** Neutral leave reporting (minutes only, no monetary liability). */
export default function LeaveReports() {
  const { t } = useTranslation()
  const [summary, setSummary] = useState<Summary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setSummary(await leave.summary() as unknown as Summary)
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
      <h1>{t('leave.reports')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      {summary && (
        <>
          <p>{summary.from} → {summary.to}</p>
          <table>
            <thead>
              <tr><th>{t('leave.type')}</th><th>{t('leave.requests')}</th><th>{t('leave.consumption')}</th></tr>
            </thead>
            <tbody>
              {summary.by_type.length === 0 && <tr><td colSpan={3}>{t('leave.no_data')}</td></tr>}
              {summary.by_type.map((r) => (
                <tr key={r.leave_type_id}>
                  <td>{r.leave_type_id}</td>
                  <td>{r.requests}</td>
                  <td>{minutes(r.consumption_minutes)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr><td>{t('leave.total')}</td><td></td><td>{minutes(summary.total_consumption_minutes)}</td></tr>
            </tfoot>
          </table>
        </>
      )}
    </div>
  )
}
