import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  attendance,
  minutes,
  type AttendanceRecord,
  type AttendanceSummary,
} from '../../attendance/api'

/** Admin/HR view of attendance records (organizationally scoped) + a summary. */
export default function AttendanceRecords() {
  const { t } = useTranslation()
  const [records, setRecords] = useState<AttendanceRecord[]>([])
  const [summary, setSummary] = useState<AttendanceSummary | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    const params: Record<string, unknown> = {}
    if (from) params.from = from
    if (to) params.to = to
    const [rows, sum] = await Promise.all([attendance.records(params), attendance.summary(params)])
    setRecords(rows)
    setSummary(sum)
    setLoading(false)
  }, [from, to])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  return (
    <div>
      <h1>{t('attendance.records.title')}</h1>

      <div className="filters">
        <label>
          {t('attendance.filters.from')}
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>
          {t('attendance.filters.to')}
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
      </div>

      {summary && (
        <div className="cards">
          <div className="card"><div className="card-label">{t('attendance.summary.records')}</div><div className="card-value">{summary.records}</div></div>
          <div className="card"><div className="card-label">{t('attendance.summary.present')}</div><div className="card-value">{summary.present}</div></div>
          <div className="card"><div className="card-label">{t('attendance.summary.late')}</div><div className="card-value">{summary.late}</div></div>
          <div className="card"><div className="card-label">{t('attendance.summary.worked')}</div><div className="card-value">{minutes(summary.worked_minutes)}</div></div>
          <div className="card"><div className="card-label">{t('attendance.summary.overtime')}</div><div className="card-value">{minutes(summary.overtime_minutes)}</div></div>
        </div>
      )}

      {loading ? (
        <p>{t('common.loading')}</p>
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th>{t('attendance.fields.employee')}</th>
              <th>{t('attendance.fields.work_date')}</th>
              <th>{t('attendance.fields.status')}</th>
              <th>{t('attendance.fields.check_in')}</th>
              <th>{t('attendance.fields.check_out')}</th>
              <th>{t('attendance.fields.worked')}</th>
              <th>{t('attendance.fields.source')}</th>
            </tr>
          </thead>
          <tbody>
            {records.length === 0 && (
              <tr><td colSpan={7} className="muted">{t('attendance.records.empty')}</td></tr>
            )}
            {records.map((r) => (
              <tr key={r.id}>
                <td className="mono">{r.employee_id.slice(0, 8)}</td>
                <td>{r.work_date}</td>
                <td><span className={`pill pill-${r.status}`}>{t(`attendance.status.${r.status}`)}</span></td>
                <td>{r.check_in_at ? new Date(r.check_in_at).toLocaleTimeString() : '—'}</td>
                <td>{r.check_out_at ? new Date(r.check_out_at).toLocaleTimeString() : '—'}</td>
                <td>{minutes(r.worked_minutes)}</td>
                <td>{t(`attendance.source.${r.source}`)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
