import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { reports, type OrgSummary, type OrgTurnover } from '../../reports/api'

/**
 * Sprint 8A: organization / workforce management reports (headcount + turnover).
 * Read-only aggregates gated by employees.reports.view and scoped server-side;
 * the UI only renders the neutral counts the API returns.
 */
export default function OrgReports() {
  const { t } = useTranslation()
  const [summary, setSummary] = useState<OrgSummary | null>(null)
  const [turnover, setTurnover] = useState<OrgTurnover | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const params: { from?: string; to?: string } = {}
      if (from) params.from = from
      if (to) params.to = to
      const [s, tv] = await Promise.all([
        reports.organizationSummary(),
        reports.organizationTurnover(params),
      ])
      setSummary(s.data)
      setTurnover(tv.data)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setLoading(false)
    }
  }, [from, to])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  return (
    <div className="page">
      <h1>{t('reports.org.title')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      <div className="filters">
        <label>{t('reports.filters.from')}
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label>{t('reports.filters.to')}
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
      </div>

      {loading || !summary || !turnover ? (
        <p role="status">{t('common.loading')}</p>
      ) : (
        <>
          <section className="cards">
            <div className="card">
              <div className="card-label">{t('reports.org.total')}</div>
              <div className="card-value">{summary.total}</div>
            </div>
            <div className="card">
              <div className="card-label">{t('reports.org.active')}</div>
              <div className="card-value">{summary.active}</div>
            </div>
            <div className="card">
              <div className="card-label">{t('reports.org.inactive')}</div>
              <div className="card-value">{summary.inactive}</div>
            </div>
          </section>

          <section className="card">
            <h3>{t('reports.org.by_status')}</h3>
            <table>
              <thead><tr><th>{t('reports.org.status')}</th><th>{t('reports.org.count')}</th></tr></thead>
              <tbody>
                {summary.by_employment_status.map((r) => (
                  <tr key={r.key ?? 'unassigned'}>
                    <td>{r.key ? t(`reports.employment_status.${r.key}`, r.key) : t('reports.org.unassigned')}</td>
                    <td>{r.count}</td>
                  </tr>
                ))}
                {summary.by_employment_status.length === 0 && <tr><td colSpan={2} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>

          <section className="card">
            <h3>{t('reports.org.by_branch')}</h3>
            <table>
              <thead><tr><th>{t('reports.org.branch')}</th><th>{t('reports.org.count')}</th></tr></thead>
              <tbody>
                {summary.by_branch.map((r) => (
                  <tr key={r.key ?? 'unassigned'}>
                    <td>{r.key ?? t('reports.org.unassigned')}</td>
                    <td>{r.count}</td>
                  </tr>
                ))}
                {summary.by_branch.length === 0 && <tr><td colSpan={2} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>

          <section className="card">
            <h3>{t('reports.org.by_department')}</h3>
            <table>
              <thead><tr><th>{t('reports.org.department')}</th><th>{t('reports.org.count')}</th></tr></thead>
              <tbody>
                {summary.by_department.map((r) => (
                  <tr key={r.key ?? 'unassigned'}>
                    <td>{r.key ?? t('reports.org.unassigned')}</td>
                    <td>{r.count}</td>
                  </tr>
                ))}
                {summary.by_department.length === 0 && <tr><td colSpan={2} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>

          <section className="card">
            <h3>{t('reports.org.turnover')}</h3>
            <p className="muted">
              {turnover.from} — {turnover.to} · {t('reports.org.joiners')}: {turnover.joiners_total} · {t('reports.org.leavers')}: {turnover.leavers_total}
            </p>
            <table>
              <thead><tr><th>{t('reports.org.month')}</th><th>{t('reports.org.joiners')}</th><th>{t('reports.org.leavers')}</th></tr></thead>
              <tbody>
                {turnover.by_month.map((m) => (
                  <tr key={m.month}><td>{m.month}</td><td>{m.joiners}</td><td>{m.leavers}</td></tr>
                ))}
                {turnover.by_month.length === 0 && <tr><td colSpan={3} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>
        </>
      )}
    </div>
  )
}
