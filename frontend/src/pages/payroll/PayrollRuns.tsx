import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { payroll, type PayrollPeriod, type PayrollRun } from '../../payroll/api'

/**
 * Payroll runs — Phase-1 skeleton (create / view / cancel). Calculation,
 * approval and finalization arrive in a later phase.
 */
export default function PayrollRuns() {
  const { t } = useTranslation()
  const [runs, setRuns] = useState<PayrollRun[]>([])
  const [periods, setPeriods] = useState<PayrollPeriod[]>([])
  const [periodId, setPeriodId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [r, p] = await Promise.all([payroll.runs(), payroll.periods()])
      setRuns(r)
      setPeriods(p)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    if (!periodId) return
    setBusy(true)
    setError(null)
    try {
      await payroll.createRun(periodId)
      setPeriodId('')
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function cancel(run: PayrollRun) {
    try {
      await payroll.cancelRun(run.id)
      await load()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <div className="page">
      <h1>{t('nav.payroll_runs')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="card">
        <select value={periodId} onChange={(e) => setPeriodId(e.target.value)} aria-label={t('payroll.select_period')}>
          <option value="">{t('payroll.select_period')}</option>
          {periods.filter((p) => p.status === 'open').map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
        </select>
        <button type="button" onClick={() => void create()} disabled={busy || !periodId}>{t('payroll.create_run')}</button>
      </div>
      <table>
        <thead><tr><th>{t('payroll.run_period')}</th><th>{t('payroll.run_status')}</th><th /></tr></thead>
        <tbody>
          {runs.map((r) => (
            <tr key={r.id}>
              <td>{periods.find((p) => p.id === r.payroll_period_id)?.label ?? r.payroll_period_id}</td>
              <td>{t(`payroll.run_status_${r.status}`)}</td>
              <td>
                <Link className="btn-link" to={`/payroll/runs/${r.id}`}>{t('payroll.view_detail')}</Link>
                {!['finalized', 'cancelled'].includes(r.status) && (
                  <button type="button" className="btn-ghost" onClick={() => void cancel(r)}>{t('payroll.cancel_run')}</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
