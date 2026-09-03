import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import {
  payroll,
  type PayrollEntry,
  type PayrollPeriod,
  type PayrollRun,
  type RunSummary,
} from '../../payroll/api'

/**
 * Payroll run calculation & review (Phase 2A). Request calculation/recalculation,
 * watch queued progress (poll while calculating), and review per-employee results,
 * a grouped-by-currency summary, and per-entry line breakdowns. No approval,
 * finalization, payslips, adjustments or reports here — those are a later phase.
 */
export default function PayrollRunDetail() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { id = '' } = useParams()
  const canCalculate = can('payroll.calculate')

  const [run, setRun] = useState<PayrollRun | null>(null)
  const [period, setPeriod] = useState<PayrollPeriod | null>(null)
  const [entries, setEntries] = useState<PayrollEntry[]>([])
  const [summary, setSummary] = useState<RunSummary | null>(null)
  const [openEntry, setOpenEntry] = useState<PayrollEntry | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const r = await payroll.run(id)
      setRun(r)
      const [periods, e, s] = await Promise.all([payroll.periods(), payroll.runEntries(id), payroll.runSummary(id)])
      setPeriod(periods.find((p) => p.id === r.payroll_period_id) ?? null)
      setEntries(e)
      setSummary(s)
      return r.status
    } catch (err) {
      setError((err as Error).message)
      return null
    }
  }, [id])

  // Poll while the queued calculation is in progress; stop at a terminal state.
  useEffect(() => {
    let active = true
    const tick = async () => {
      const status = await load()
      if (active && status === 'calculating') {
        timer.current = setTimeout(() => void tick(), 2500)
      }
    }
    void tick()
    return () => {
      active = false
      if (timer.current) clearTimeout(timer.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  async function calculate(method: 'calculateRun' | 'recalculateRun') {
    setBusy(true)
    setError(null)
    try {
      await payroll[method](id)
      await load()
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function openLines(entry: PayrollEntry) {
    try {
      setOpenEntry(await payroll.entry(entry.id))
    } catch (err) {
      setError((err as Error).message)
    }
  }

  const status = run?.status ?? ''
  const calculating = status === 'calculating'
  const canRecalculate = ['calculated', 'calculation_failed'].includes(status)
  const canStart = ['draft', 'calculation_failed'].includes(status)

  return (
    <div className="page">
      <p><Link to="/payroll/runs">← {t('nav.payroll_runs')}</Link></p>
      <h1>{t('payroll.run_detail')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      <div className="card">
        <p><strong>{t('payroll.run_period')}:</strong> {period?.label ?? run?.payroll_period_id}</p>
        <p><strong>{t('payroll.run_status')}:</strong> {status ? t(`payroll.run_status_${status}`) : '—'}</p>
        {run?.calculation_version && <p><strong>{t('payroll.calculation_version')}:</strong> {run.calculation_version}</p>}
        {calculating && <p role="status">{t('payroll.calculating_in_progress')}</p>}
        {canCalculate && (
          <div className="row">
            {canStart && <button type="button" onClick={() => void calculate('calculateRun')} disabled={busy || calculating}>{t('payroll.calculate')}</button>}
            {canRecalculate && <button type="button" onClick={() => void calculate('recalculateRun')} disabled={busy || calculating}>{t('payroll.recalculate')}</button>}
          </div>
        )}
      </div>

      {summary && (
        <section className="card">
          <h3>{t('payroll.summary')}</h3>
          <p>
            {t('payroll.cohort')}: {summary.counts.cohort} · {t('payroll.status_calculated')}: {summary.counts.calculated} · {t('payroll.status_failed')}: {summary.counts.failed} · {t('payroll.status_pending')}: {summary.counts.pending}
          </p>
          <table>
            <thead><tr><th>{t('payroll.currency')}</th><th>{t('payroll.gross')}</th><th>{t('payroll.deductions')}</th><th>{t('payroll.net')}</th><th>{t('payroll.employees')}</th></tr></thead>
            <tbody>
              {summary.by_currency.map((g) => (
                <tr key={g.currency}><td>{g.currency}</td><td>{g.gross_minor}</td><td>{g.deduction_minor}</td><td>{g.net_minor}</td><td>{g.employee_count}</td></tr>
              ))}
              {summary.by_currency.length === 0 && <tr><td colSpan={5}>{t('common.none')}</td></tr>}
            </tbody>
          </table>
        </section>
      )}

      <section className="card">
        <h3>{t('payroll.entries')}</h3>
        <table>
          <thead><tr><th>{t('payroll.employee')}</th><th>{t('payroll.entry_status')}</th><th>{t('payroll.currency')}</th><th>{t('payroll.gross')}</th><th>{t('payroll.deductions')}</th><th>{t('payroll.net')}</th><th>{t('payroll.notes')}</th><th /></tr></thead>
          <tbody>
            {entries.map((e) => (
              <tr key={e.id}>
                <td>{e.employee.employee_number} — {e.employee.name}</td>
                <td>{t(`payroll.entry_status_${e.status}`)}</td>
                <td>{e.currency ?? '—'}</td>
                <td>{e.gross_minor ?? '—'}</td>
                <td>{e.deduction_minor ?? '—'}</td>
                <td>{e.net_minor ?? '—'}</td>
                <td>
                  {e.error_code && <span className="error">{t(`payroll.error_${e.error_code}`, e.error_code)}</span>}
                  {e.negative_net && <span className="error">{t('payroll.warning_negative_net')}</span>}
                </td>
                <td>{e.status === 'calculated' && <button type="button" className="btn-link" onClick={() => void openLines(e)}>{t('payroll.view_lines')}</button>}</td>
              </tr>
            ))}
            {entries.length === 0 && <tr><td colSpan={8}>{t('common.none')}</td></tr>}
          </tbody>
        </table>
      </section>

      {openEntry && (
        <section className="card">
          <h3>{t('payroll.lines_for')} {openEntry.employee.name}</h3>
          <button type="button" className="btn-ghost" onClick={() => setOpenEntry(null)}>{t('common.cancel')}</button>
          <table>
            <thead><tr><th>{t('payroll.line')}</th><th>{t('payroll.direction')}</th><th>{t('payroll.quantity_minutes')}</th><th>{t('payroll.amount')}</th></tr></thead>
            <tbody>
              {(openEntry.lines ?? []).map((l) => (
                <tr key={l.id}>
                  <td>{l.label}</td>
                  <td>{t(`payroll.direction_${l.direction}`)}</td>
                  <td>{l.quantity_minutes ?? '—'}</td>
                  <td>{l.amount_minor}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </div>
  )
}
