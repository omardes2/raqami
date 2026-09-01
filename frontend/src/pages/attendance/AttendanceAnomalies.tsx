import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type AttendanceAnomaly } from '../../attendance/api'

/** Attendance anomaly review (neutral findings; no disciplinary action). */
export default function AttendanceAnomalies() {
  const { t } = useTranslation()
  const [items, setItems] = useState<AttendanceAnomaly[]>([])
  const [status, setStatus] = useState('open')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setItems(await attendance.anomalies({ status }))
    setLoading(false)
  }, [status])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function errorText(e: unknown): string {
    const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    return err.response?.data?.errors?.status?.[0] ?? err.response?.data?.message ?? t('common.error')
  }

  async function review(id: string, next: string) {
    const note = next === 'resolved' ? (window.prompt(t('attendance.anomalies.note') ?? '') ?? undefined) : undefined
    setError(null)
    try {
      await attendance.reviewAnomaly(id, next, note)
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  return (
    <div>
      <h1>{t('attendance.anomalies.title')}</h1>
      <p className="muted">{t('attendance.anomalies.intro')}</p>

      <div className="filters">
        <label>
          {t('attendance.fields.status')}
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="open">{t('attendance.anomaly_status.open')}</option>
            <option value="acknowledged">{t('attendance.anomaly_status.acknowledged')}</option>
            <option value="resolved">{t('attendance.anomaly_status.resolved')}</option>
            <option value="dismissed">{t('attendance.anomaly_status.dismissed')}</option>
          </select>
        </label>
      </div>

      {error && <p className="error">{error}</p>}

      {loading ? (
        <p>{t('common.loading')}</p>
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('attendance.fields.employee')}</th>
              <th>{t('attendance.anomalies.type')}</th>
              <th>{t('attendance.anomalies.severity')}</th>
              <th>{t('attendance.anomalies.detected_at')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={6} className="muted">{t('attendance.anomalies.empty')}</td></tr>}
            {items.map((a) => (
              <tr key={a.id}>
                <td className="mono">{a.employee_id.slice(0, 8)}</td>
                <td>{t(`attendance.anomaly_type.${a.type}`)}</td>
                <td><span className={`pill pill-${a.severity}`}>{t(`attendance.anomaly_severity.${a.severity}`)}</span></td>
                <td>{a.detected_at ? new Date(a.detected_at).toLocaleString() : '—'}</td>
                <td>{t(`attendance.anomaly_status.${a.status}`)}</td>
                <td>
                  {a.status === 'open' || a.status === 'acknowledged' ? (
                    <div className="row-actions">
                      {a.status === 'open' && (
                        <button type="button" className="btn-link" onClick={() => review(a.id, 'acknowledged')}>{t('attendance.anomalies.acknowledge')}</button>
                      )}
                      <button type="button" className="btn-link" onClick={() => review(a.id, 'resolved')}>{t('attendance.anomalies.resolve')}</button>
                      <button type="button" className="btn-link danger" onClick={() => review(a.id, 'dismissed')}>{t('attendance.anomalies.dismiss')}</button>
                    </div>
                  ) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
