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
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr>
                <td colSpan={7} className="muted">{t('attendance.my.no_records')}</td>
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
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  )
}
