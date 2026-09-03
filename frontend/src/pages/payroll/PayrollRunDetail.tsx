import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { payroll, type PayrollPeriod, type PayrollRun } from '../../payroll/api'

/**
 * Payroll run detail — Phase-1 lifecycle metadata only (period, status, and the
 * lifecycle timestamps the API exposes). Calculation, entries, approval,
 * finalization, payslips and reports belong to a later phase and are not shown.
 */
export default function PayrollRunDetail() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [run, setRun] = useState<PayrollRun | null>(null)
  const [period, setPeriod] = useState<PayrollPeriod | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const r = await payroll.run(id)
      setRun(r)
      const periods = await payroll.periods()
      setPeriod(periods.find((p) => p.id === r.payroll_period_id) ?? null)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [id])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  const rows: Array<[string, string]> = run
    ? [
        [t('payroll.run_period'), period?.label ?? run.payroll_period_id],
        [t('payroll.run_status'), t(`payroll.run_status_${run.status}`)],
        [t('payroll.calculation_version'), run.calculation_version ?? '—'],
        [t('payroll.calculated_at'), run.calculated_at ?? '—'],
        [t('payroll.approved_at'), run.approved_at ?? '—'],
        [t('payroll.finalized_at'), run.finalized_at ?? '—'],
        [t('payroll.cancelled_at'), run.cancelled_at ?? '—'],
      ]
    : []

  return (
    <div className="page">
      <p><Link to="/payroll/runs">← {t('nav.payroll_runs')}</Link></p>
      <h1>{t('payroll.run_detail')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      {run && (
        <table>
          <tbody>
            {rows.map(([label, value]) => (
              <tr key={label}><th style={{ textAlign: 'start' }}>{label}</th><td>{value}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
