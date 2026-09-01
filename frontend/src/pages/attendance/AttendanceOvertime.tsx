import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, minutes, type OvertimeApproval } from '../../attendance/api'

/** Overtime approval queue: raw calculated vs reviewer-approved minutes. */
export default function AttendanceOvertime() {
  const { t } = useTranslation()
  const [items, setItems] = useState<OvertimeApproval[]>([])
  const [status, setStatus] = useState('pending')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setItems(await attendance.overtime({ status }))
    setLoading(false)
  }, [status])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function errorText(e: unknown): string {
    const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    return err.response?.data?.errors?.attendance?.[0] ?? err.response?.data?.message ?? t('common.error')
  }

  async function approve(item: OvertimeApproval) {
    const input = window.prompt(t('attendance.overtime.approved_prompt') ?? '', String(item.calculated_minutes))
    if (input === null) return
    setError(null)
    try {
      await attendance.approveOvertime(item.id, { approved_minutes: Number(input) })
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  async function reject(id: string) {
    const notes = window.prompt(t('attendance.overtime.reject_notes') ?? '') ?? undefined
    setError(null)
    try {
      await attendance.rejectOvertime(id, notes)
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  return (
    <div>
      <h1>{t('attendance.overtime.title')}</h1>

      <div className="filters">
        <label>
          {t('attendance.fields.status')}
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="pending">{t('attendance.overtime_status.pending')}</option>
            <option value="approved">{t('attendance.overtime_status.approved')}</option>
            <option value="rejected">{t('attendance.overtime_status.rejected')}</option>
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
              <th>{t('attendance.fields.work_date')}</th>
              <th>{t('attendance.overtime.calculated')}</th>
              <th>{t('attendance.overtime.approved')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={6} className="muted">{t('attendance.overtime.empty')}</td></tr>}
            {items.map((o) => (
              <tr key={o.id}>
                <td className="mono">{o.employee_id.slice(0, 8)}</td>
                <td>{o.work_date}</td>
                <td>{minutes(o.calculated_minutes)}</td>
                <td>{o.approved_minutes === null ? '—' : minutes(o.approved_minutes)}</td>
                <td><span className={`pill pill-${o.status}`}>{t(`attendance.overtime_status.${o.status}`)}</span></td>
                <td>
                  {o.status === 'pending' ? (
                    <div className="row-actions">
                      <button type="button" className="btn-link" onClick={() => approve(o)}>{t('attendance.overtime.approve')}</button>
                      <button type="button" className="btn-link danger" onClick={() => reject(o.id)}>{t('attendance.overtime.reject')}</button>
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
