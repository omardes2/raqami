import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  attendance,
  minutes,
  readGeolocation,
  type AttendanceRecord,
} from '../../attendance/api'

/**
 * Employee self-service. The client only sends GPS facts; the server decides
 * lateness, worked minutes, and geofence membership. A generated request id makes
 * a retried punch idempotent.
 */
export default function MyAttendance() {
  const { t } = useTranslation()
  const [open, setOpen] = useState<AttendanceRecord | null>(null)
  const [records, setRecords] = useState<AttendanceRecord[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [correcting, setCorrecting] = useState<AttendanceRecord | null>(null)

  const load = useCallback(async () => {
    const [today, mine] = await Promise.all([attendance.today(), attendance.myRecords({ per_page: 31 })])
    setOpen(today.open)
    setRecords(mine)
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function punch(direction: 'in' | 'out') {
    setBusy(true)
    setError(null)
    try {
      const coords = await readGeolocation()
      const payload = {
        ...(coords ?? {}),
        client_request_id: crypto.randomUUID(),
      }
      if (direction === 'in') await attendance.checkIn(payload)
      else await attendance.checkOut(payload)
      await load()
    } catch (e) {
      const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const firstError = err.response?.data?.errors?.attendance?.[0]
      setError(firstError ?? err.response?.data?.message ?? t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  async function submitCorrection(payload: Record<string, unknown>) {
    if (!correcting) return
    setError(null)
    try {
      await attendance.requestCorrection(correcting.id, payload)
      setCorrecting(null)
      await load()
    } catch (e) {
      const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      const errs = err.response?.data?.errors
      const first = errs ? (errs.attendance?.[0] ?? Object.values(errs)[0]?.[0]) : undefined
      setError(first ?? err.response?.data?.message ?? t('common.error'))
    }
  }

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div>
      <h1>{t('attendance.my.title')}</h1>

      <section className="card">
        <h3>{t('attendance.my.status')}</h3>
        {open ? (
          <>
            <p className="big">
              <span className={`pill pill-${open.status}`}>{t(`attendance.status.${open.status}`)}</span>
            </p>
            <p className="muted">
              {t('attendance.my.checked_in_at')}:{' '}
              {open.check_in_at ? new Date(open.check_in_at).toLocaleTimeString() : '—'}
            </p>
            {open.late_minutes > 0 && (
              <p className="muted">{t('attendance.fields.late')}: {minutes(open.late_minutes)}</p>
            )}
            <button type="button" className="btn-primary" disabled={busy} onClick={() => punch('out')}>
              {busy ? t('common.loading') : t('attendance.my.check_out')}
            </button>
          </>
        ) : (
          <>
            <p className="muted">{t('attendance.my.not_checked_in')}</p>
            <button type="button" className="btn-primary" disabled={busy} onClick={() => punch('in')}>
              {busy ? t('common.loading') : t('attendance.my.check_in')}
            </button>
          </>
        )}
        {error && <p className="error">{error}</p>}
        <p className="muted small">{t('attendance.my.gps_hint')}</p>
      </section>

      <section>
        <h3>{t('attendance.my.history')}</h3>
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('attendance.fields.work_date')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.check_in')}</th>
              <th>{t('attendance.fields.check_out')}</th>
              <th>{t('attendance.fields.worked')}</th>
              <th>{t('attendance.fields.late')}</th>
              <th>{t('attendance.fields.overtime')}</th>
              <th>{t('attendance.fields.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr>
                <td colSpan={8} className="muted">{t('attendance.my.no_records')}</td>
              </tr>
            )}
            {records.map((r) => (
              <tr key={r.id}>
                <td>{r.work_date}</td>
                <td><span className={`pill pill-${r.status}`}>{t(`attendance.status.${r.status}`)}</span></td>
                <td>{r.check_in_at ? new Date(r.check_in_at).toLocaleTimeString() : '—'}</td>
                <td>{r.check_out_at ? new Date(r.check_out_at).toLocaleTimeString() : '—'}</td>
                <td>{minutes(r.worked_minutes)}</td>
                <td>{r.late_minutes > 0 ? minutes(r.late_minutes) : '—'}</td>
                <td>{r.overtime_minutes > 0 ? minutes(r.overtime_minutes) : '—'}</td>
                <td>
                  <button type="button" className="btn-link" onClick={() => { setCorrecting(r); setError(null) }}>
                    {t('attendance.my.request_correction')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {correcting && (
        <CorrectionForm
          record={correcting}
          onCancel={() => setCorrecting(null)}
          onSubmit={submitCorrection}
        />
      )}
    </div>
  )
}

/**
 * Request a punch-time correction. On a multi-session day the employee MUST pick
 * which session to correct — the server also enforces this. The server decides
 * every resulting minute; this form only collects the requested times + reason.
 */
function CorrectionForm({
  record,
  onCancel,
  onSubmit,
}: {
  record: AttendanceRecord
  onCancel: () => void
  onSubmit: (payload: Record<string, unknown>) => void
}) {
  const { t } = useTranslation()
  const sessions = record.sessions ?? []
  const multi = sessions.length > 1
  const [sessionId, setSessionId] = useState(sessions.length === 1 ? sessions[0].id : '')
  const [checkIn, setCheckIn] = useState('')
  const [checkOut, setCheckOut] = useState('')
  const [reason, setReason] = useState('')

  function submit(e: React.FormEvent) {
    e.preventDefault()
    const payload: Record<string, unknown> = { reason }
    if (sessionId) payload.attendance_session_id = sessionId
    if (checkIn) payload.requested_check_in_at = new Date(checkIn).toISOString()
    if (checkOut) payload.requested_check_out_at = new Date(checkOut).toISOString()
    onSubmit(payload)
  }

  return (
    <section className="card">
      <h3>{t('attendance.my.request_correction')} — {record.work_date}</h3>
      <form className="form-narrow" onSubmit={submit}>
        {multi && (
          <label>
            {t('attendance.my.correction_session')}
            <select value={sessionId} onChange={(e) => setSessionId(e.target.value)} required>
              <option value="">{t('attendance.my.correction_session_pick')}</option>
              {sessions.map((s) => (
                <option key={s.id} value={s.id}>
                  #{s.sequence} · {s.check_in_at ? new Date(s.check_in_at).toLocaleTimeString() : '—'}
                  {' → '}
                  {s.check_out_at ? new Date(s.check_out_at).toLocaleTimeString() : '—'}
                </option>
              ))}
            </select>
          </label>
        )}
        <label>
          {t('attendance.my.correction_new_in')}
          <input type="datetime-local" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} />
        </label>
        <label>
          {t('attendance.my.correction_new_out')}
          <input type="datetime-local" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} />
        </label>
        <label>
          {t('attendance.corrections.reason')}
          <input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </label>
        <div className="form-actions">
          <button type="submit" className="btn-primary">{t('attendance.my.correction_submit')}</button>
          <button type="button" className="btn-ghost" onClick={onCancel}>{t('common.cancel')}</button>
        </div>
      </form>
    </section>
  )
}
