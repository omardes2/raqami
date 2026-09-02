import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, minutes, type AdvancedReport, type EmployeeRollup } from '../../attendance/api'

/** Advanced attendance reports: compliance rates, status breakdown, overtime. */
export default function AttendanceReports() {
  const { t } = useTranslation()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [report, setReport] = useState<AdvancedReport | null>(null)
  const [rows, setRows] = useState<EmployeeRollup[]>([])
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    const params: Record<string, unknown> = {}
    if (from) params.from = from
    if (to) params.to = to
    const [adv, byEmp] = await Promise.all([
      attendance.advancedReport(params),
      attendance.byEmployeeReport(params),
    ])
    setReport(adv)
    setRows(byEmp)
    setLoading(false)
  }, [from, to])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function pct(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)}%`
  }

  return (
    <div>
      <h1>{t('attendance.reports.title')}</h1>

      <div className="filters">
        <label>{t('attendance.filters.from')}
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>{t('attendance.filters.to')}
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
      </div>

      {loading || !report ? (
        <p>{t('common.loading')}</p>
      ) : (
        <>
          <section className="cards">
            <div className="stat">
              <span className="stat-label">{t('attendance.reports.attendance_rate')}</span>
              <span className="stat-value">{pct(report.compliance.attendance_rate)}</span>
            </div>
            <div className="stat">
              <span className="stat-label">{t('attendance.reports.punctuality_rate')}</span>
              <span className="stat-value">{pct(report.compliance.punctuality_rate)}</span>
            </div>
            <div className="stat">
              <span className="stat-label">{t('attendance.reports.scheduled_days')}</span>
              <span className="stat-value">{report.compliance.scheduled_days}</span>
            </div>
            <div className="stat">
              <span className="stat-label">{t('attendance.reports.overtime_approved')}</span>
              <span className="stat-value">{minutes(report.overtime.approved_minutes)}</span>
            </div>
          </section>

          <h2>{t('attendance.reports.status_breakdown')}</h2>
          <table className="data-table">
            <thead><tr><th>{t('attendance.fields.status')}</th><th>{t('attendance.summary.records')}</th></tr></thead>
            <tbody>
              {Object.entries(report.status_breakdown).map(([key, count]) => (
                <tr key={key}><td>{t(`attendance.status.${key}`)}</td><td>{count}</td></tr>
              ))}
            </tbody>
          </table>

          <h2>{t('attendance.reports.overtime')}</h2>
          <p className="muted">
            {t('attendance.reports.overtime_calculated')}: {minutes(report.overtime.calculated_minutes)} ·{' '}
            {t('attendance.reports.overtime_approved')}: {minutes(report.overtime.approved_minutes)} ·{' '}
            {t('attendance.overtime_status.pending')}: {report.overtime.pending}
          </p>

          <h2>{t('attendance.reports.by_employee')}</h2>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('attendance.fields.employee')}</th>
                <th>{t('attendance.status.present')}</th>
                <th>{t('attendance.status.late')}</th>
                <th>{t('attendance.status.absent')}</th>
                <th>{t('attendance.fields.worked')}</th>
                <th>{t('attendance.fields.overtime')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && <tr><td colSpan={6} className="muted">{t('attendance.records.empty')}</td></tr>}
              {rows.map((r) => (
                <tr key={r.employee_id}>
                  <td className="mono">{r.employee_id.slice(0, 8)}</td>
                  <td>{r.present}</td>
                  <td>{r.late}</td>
                  <td>{r.absent}</td>
                  <td>{minutes(r.worked_minutes)}</td>
                  <td>{minutes(r.overtime_minutes)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </div>
  )
}
