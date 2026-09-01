import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type AttendanceCorrection } from '../../attendance/api'

/** Review queue for attendance corrections (approve / reject; no self-approval). */
export default function AttendanceCorrections() {
  const { t } = useTranslation()
  const [items, setItems] = useState<AttendanceCorrection[]>([])
  const [status, setStatus] = useState('pending')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setItems(await attendance.corrections({ status }))
    setLoading(false)
  }, [status])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function approve(id: string) {
    setError(null)
    try {
      await attendance.approveCorrection(id)
      await load()
    } catch (e) {
      setError(errorText(e))
    }
  }

  async function reject(id: string) {
    const reason = window.prompt(t('attendance.corrections.reject_reason') ?? '')
    if (!reason) return
    setError(null)
    try {
      await attendance.rejectCorrection(id, reason)
      await load()
    } catch (e) {
      setError(errorText(e))
    }
  }

  function errorText(e: unknown): string {
    const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    return err.response?.data?.errors?.attendance?.[0] ?? err.response?.data?.message ?? t('common.error')
  }

  return (
    <div>
      <h1>{t('attendance.corrections.title')}</h1>

      <div className="filters">
        <label>
          {t('attendance.fields.status')}
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="pending">{t('attendance.correction_status.pending')}</option>
            <option value="approved">{t('attendance.correction_status.approved')}</option>
            <option value="rejected">{t('attendance.correction_status.rejected')}</option>
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
              <th>{t('attendance.corrections.requested_in')}</th>
              <th>{t('attendance.corrections.requested_out')}</th>
              <th>{t('attendance.corrections.reason')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && (
              <tr><td colSpan={6} className="muted">{t('attendance.corrections.empty')}</td></tr>
            )}
            {items.map((c) => (
              <tr key={c.id}>
                <td className="mono">{c.employee_id.slice(0, 8)}</td>
                <td>{c.requested_check_in_at ? new Date(c.requested_check_in_at).toLocaleString() : '—'}</td>
                <td>{c.requested_check_out_at ? new Date(c.requested_check_out_at).toLocaleString() : '—'}</td>
                <td>{c.reason}</td>
                <td><span className={`pill pill-${c.status}`}>{t(`attendance.correction_status.${c.status}`)}</span></td>
                <td>
                  {c.status === 'pending' ? (
                    <div className="row-actions">
                      <button type="button" className="btn-link" onClick={() => approve(c.id)}>{t('attendance.corrections.approve')}</button>
                      <button type="button" className="btn-link danger" onClick={() => reject(c.id)}>{t('attendance.corrections.reject')}</button>
                    </div>
                  ) : (
                    '—'
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
