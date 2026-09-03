import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { payroll, type PayrollPeriod } from '../../payroll/api'

/** Monthly payroll periods (payroll.runs.manage to create). */
export default function PayrollPeriods() {
  const { t } = useTranslation()
  const [items, setItems] = useState<PayrollPeriod[]>([])
  const [month, setMonth] = useState('') // YYYY-MM
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setItems(await payroll.periods())
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    if (!month) return
    setBusy(true)
    setError(null)
    try {
      await payroll.createPeriod({ period_start: `${month}-01`, label: month })
      setMonth('')
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <h1>{t('nav.payroll_periods')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="card">
        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} aria-label={t('payroll.period_month')} />
        <button type="button" onClick={() => void create()} disabled={busy || !month}>{t('payroll.create_period')}</button>
      </div>
      <table>
        <thead><tr><th>{t('payroll.period_label')}</th><th>{t('payroll.period_start')}</th><th>{t('payroll.period_end')}</th><th>{t('payroll.period_status')}</th></tr></thead>
        <tbody>
          {items.map((p) => (
            <tr key={p.id}>
              <td>{p.label}</td><td>{p.period_start}</td><td>{p.period_end}</td><td>{t(`payroll.period_status_${p.status}`)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
