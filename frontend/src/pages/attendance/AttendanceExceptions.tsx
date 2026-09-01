import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type AttendanceException } from '../../attendance/api'

const TYPES = ['remote', 'field', 'off_day_work', 'alternate_location', 'schedule_override']

/** Authorized attendance exceptions (remote / field / off-day / alternate). */
export default function AttendanceExceptions() {
  const { t } = useTranslation()
  const [items, setItems] = useState<AttendanceException[]>([])
  const [status, setStatus] = useState('active')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const [employeeId, setEmployeeId] = useState('')
  const [type, setType] = useState('remote')
  const [from, setFrom] = useState('')
  const [until, setUntil] = useState('')
  const [reason, setReason] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setItems(await attendance.exceptions({ status }))
    setLoading(false)
  }, [status])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function errorText(e: unknown): string {
    const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    const errors = err.response?.data?.errors
    const first = errors ? Object.values(errors)[0]?.[0] : undefined
    return first ?? err.response?.data?.message ?? t('common.error')
  }

  async function create(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await attendance.createException({
        employee_id: employeeId,
        type,
        effective_from: from,
        effective_until: until || null,
        reason,
      })
      setEmployeeId('')
      setReason('')
      setFrom('')
      setUntil('')
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  async function revoke(id: string) {
    setError(null)
    try {
      await attendance.revokeException(id)
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  return (
    <div>
      <h1>{t('attendance.exceptions.title')}</h1>

      <form className="inline-form" onSubmit={create}>
        <input value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} placeholder={t('attendance.exceptions.employee_id') ?? ''} required />
        <select value={type} onChange={(e) => setType(e.target.value)}>
          {TYPES.map((ty) => <option key={ty} value={ty}>{t(`attendance.exception_type.${ty}`)}</option>)}
        </select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} required aria-label={t('attendance.exceptions.effective_from') ?? ''} />
        <input type="date" value={until} onChange={(e) => setUntil(e.target.value)} aria-label={t('attendance.exceptions.effective_until') ?? ''} />
        <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('attendance.exceptions.reason') ?? ''} required />
        <button type="submit" className="btn-primary">{t('attendance.exceptions.create')}</button>
      </form>

      <div className="filters">
        <label>
          {t('attendance.fields.status')}
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">{t('attendance.exception_status.active')}</option>
            <option value="revoked">{t('attendance.exception_status.revoked')}</option>
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
              <th>{t('attendance.exceptions.type')}</th>
              <th>{t('attendance.exceptions.effective_from')}</th>
              <th>{t('attendance.exceptions.effective_until')}</th>
              <th>{t('attendance.exceptions.mode')}</th>
              <th>{t('attendance.exceptions.reason')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={8} className="muted">{t('attendance.exceptions.empty')}</td></tr>}
            {items.map((ex) => (
              <tr key={ex.id}>
                <td className="mono">{ex.employee_id.slice(0, 8)}</td>
                <td>{t(`attendance.exception_type.${ex.type}`)}</td>
                <td>{ex.effective_from}</td>
                <td>{ex.effective_until ?? '—'}</td>
                <td>{ex.attendance_mode ? t(`attendance.mode.${ex.attendance_mode}`) : '—'}</td>
                <td>{ex.reason}</td>
                <td><span className={`pill pill-${ex.status}`}>{t(`attendance.exception_status.${ex.status}`)}</span></td>
                <td>
                  {ex.status === 'active'
                    ? <button type="button" className="btn-link danger" onClick={() => revoke(ex.id)}>{t('attendance.exceptions.revoke')}</button>
                    : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
