import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { money } from '../../billing/api'
import {
  reports,
  type PayrollComponentTotal,
  type PayrollPeriodTotals,
  type PayrollSummary,
} from '../../reports/api'

/**
 * Sprint 8A Phase 2: payroll management reports. Company-wide only, gated by
 * payroll.reports.view and enforced server-side; the source is immutable
 * FINALIZED history. Money is ALWAYS shown per currency and never combined
 * across currencies (no grand total, no FX) — each amount is an integer minor
 * value rendered with the shared money() helper. No charts, no export.
 */
export default function PayrollReports() {
  const { t } = useTranslation()
  const [summary, setSummary] = useState<PayrollSummary | null>(null)
  const [periods, setPeriods] = useState<PayrollPeriodTotals[]>([])
  const [components, setComponents] = useState<PayrollComponentTotal[]>([])
  const [periodId, setPeriodId] = useState('')
  const [currency, setCurrency] = useState('')
  // The list of periods for the filter is loaded once, unfiltered, so choosing a
  // period never removes the other options from the dropdown.
  const [periodOptions, setPeriodOptions] = useState<PayrollPeriodTotals[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const filters: { payroll_period_id?: string; currency?: string } = {}
      if (periodId) filters.payroll_period_id = periodId
      if (currency) filters.currency = currency
      const [s, p, c] = await Promise.all([
        reports.payrollSummary(filters),
        reports.payrollByPeriod(filters),
        reports.payrollComponents(filters),
      ])
      setSummary(s.data)
      setPeriods(p.data)
      setComponents(c.data)
      setPeriodOptions((prev) => (prev.length === 0 && !periodId && !currency ? p.data : prev))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setLoading(false)
    }
  }, [periodId, currency])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  return (
    <div className="page">
      <h1>{t('reports.payroll.title')}</h1>
      <p className="hint">{t('reports.payroll.basis')}</p>
      {error && <p className="error" role="alert">{error}</p>}

      <div className="filters">
        <label>{t('reports.payroll.period')}
          <select value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
            <option value="">{t('reports.payroll.all_periods')}</option>
            {periodOptions.map((p) => (
              <option key={p.period_id} value={p.period_id}>{p.label ?? p.period_id}</option>
            ))}
          </select>
        </label>
        <label>{t('reports.payroll.currency')}
          <select value={currency} onChange={(e) => setCurrency(e.target.value)}>
            <option value="">{t('reports.payroll.all_currencies')}</option>
            {(summary?.currencies ?? []).map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </label>
      </div>

      {loading || !summary ? (
        <p role="status">{t('common.loading')}</p>
      ) : summary.by_currency.length === 0 ? (
        <p className="muted">{t('reports.payroll.no_finalized')}</p>
      ) : (
        <>
          {/* Per-currency KPI blocks — never summed across currencies. */}
          <section className="cards">
            {summary.by_currency.map((c) => (
              <div className="card" key={c.currency}>
                <div className="card-label">{t('reports.payroll.net')} · {c.currency}</div>
                <div className="card-value">{money(c.net_minor, c.currency)}</div>
                <div className="muted">
                  {t('reports.payroll.gross')}: {money(c.gross_minor, c.currency)} ·{' '}
                  {t('reports.payroll.deductions')}: {money(c.deduction_minor, c.currency)}
                </div>
                <div className="muted">
                  {t('reports.payroll.employees')}: {c.employee_count ?? 0} ·{' '}
                  {t('reports.payroll.entries')}: {c.entry_count ?? 0}
                </div>
              </div>
            ))}
          </section>

          <section className="card">
            <h3>{t('reports.payroll.by_currency')}</h3>
            <table>
              <thead>
                <tr>
                  <th>{t('reports.payroll.currency')}</th>
                  <th>{t('reports.payroll.gross')}</th>
                  <th>{t('reports.payroll.deductions')}</th>
                  <th>{t('reports.payroll.net')}</th>
                  <th>{t('reports.payroll.employees')}</th>
                </tr>
              </thead>
              <tbody>
                {summary.by_currency.map((c) => (
                  <tr key={c.currency}>
                    <td>{c.currency}</td>
                    <td>{money(c.gross_minor, c.currency)}</td>
                    <td>{money(c.deduction_minor, c.currency)}</td>
                    <td>{money(c.net_minor, c.currency)}</td>
                    <td>{c.employee_count ?? 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>

          <section className="card">
            <h3>{t('reports.payroll.by_period')}</h3>
            <table>
              <thead>
                <tr>
                  <th>{t('reports.payroll.period')}</th>
                  <th>{t('reports.payroll.currency')}</th>
                  <th>{t('reports.payroll.gross')}</th>
                  <th>{t('reports.payroll.deductions')}</th>
                  <th>{t('reports.payroll.net')}</th>
                </tr>
              </thead>
              <tbody>
                {periods.flatMap((p) =>
                  p.by_currency.map((c) => (
                    <tr key={`${p.period_id}-${c.currency}`}>
                      <td>{p.label ?? p.period_id}</td>
                      <td>{c.currency}</td>
                      <td>{money(c.gross_minor, c.currency)}</td>
                      <td>{money(c.deduction_minor, c.currency)}</td>
                      <td>{money(c.net_minor, c.currency)}</td>
                    </tr>
                  )),
                )}
                {periods.length === 0 && <tr><td colSpan={5} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>

          <section className="card">
            <h3>{t('reports.payroll.components')}</h3>
            <table>
              <thead>
                <tr>
                  <th>{t('reports.payroll.currency')}</th>
                  <th>{t('reports.payroll.direction')}</th>
                  <th>{t('reports.payroll.component')}</th>
                  <th>{t('reports.payroll.count')}</th>
                  <th>{t('reports.payroll.amount')}</th>
                </tr>
              </thead>
              <tbody>
                {components.map((c, i) => (
                  <tr key={`${c.currency}-${c.direction}-${c.line_type}-${c.label ?? i}`}>
                    <td>{c.currency}</td>
                    <td>{t(`reports.payroll.dir.${c.direction}`, c.direction)}</td>
                    <td>{c.label ?? c.line_type}</td>
                    <td>{c.line_count}</td>
                    <td>{money(c.amount_minor, c.currency)}</td>
                  </tr>
                ))}
                {components.length === 0 && <tr><td colSpan={5} className="muted">{t('common.none')}</td></tr>}
              </tbody>
            </table>
          </section>
        </>
      )}
    </div>
  )
}
