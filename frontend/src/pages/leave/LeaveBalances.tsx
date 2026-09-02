import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, minutes, type LeaveBalance } from '../../leave/api'
import { useAuth } from '../../auth/AuthContext'

/** Management view of leave balances + manual adjustments (ledger-based). */
export default function LeaveBalances() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [balances, setBalances] = useState<LeaveBalance[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ employee_id: '', leave_type_id: '', minutes: '', reason: '', allow_negative_override: false })

  const load = useCallback(async () => {
    setBalances(await leave.balances({ per_page: 100 }))
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function adjust() {
    setBusy(true)
    setError(null)
    try {
      await leave.adjust({ ...form, minutes: Number(form.minutes) })
      setForm({ employee_id: '', leave_type_id: '', minutes: '', reason: '', allow_negative_override: false })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.balances')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      {can('leave.balances.adjust') && (
        <section>
          <h2>{t('leave.adjust')}</h2>
          <div className="form-grid">
            <label>{t('leave.employee')}<input value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} /></label>
            <label>{t('leave.type')}<input value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })} /></label>
            <label>{t('leave.minutes')}<input type="number" value={form.minutes} onChange={(e) => setForm({ ...form, minutes: e.target.value })} /></label>
            <label>{t('leave.reason')}<input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
            {can('leave.negative_override') && (
              <label><input type="checkbox" checked={form.allow_negative_override} onChange={(e) => setForm({ ...form, allow_negative_override: e.target.checked })} /> {t('leave.allow_negative')}</label>
            )}
          </div>
          <button type="button" onClick={() => void adjust()} disabled={busy || !form.employee_id || !form.leave_type_id || !form.minutes || !form.reason}>
            {t('leave.apply_adjustment')}
          </button>
        </section>
      )}

      <table>
        <thead>
          <tr>
            <th>{t('leave.employee')}</th>
            <th>{t('leave.type')}</th>
            <th>{t('leave.available')}</th>
            <th>{t('leave.reserved')}</th>
            <th>{t('leave.used')}</th>
          </tr>
        </thead>
        <tbody>
          {balances.length === 0 && <tr><td colSpan={5}>{t('leave.no_balances')}</td></tr>}
          {balances.map((b) => (
            <tr key={b.id}>
              <td>{b.employee_id}</td>
              <td>{b.leave_type_id}</td>
              <td>{minutes(b.available_minutes)}</td>
              <td>{minutes(b.reserved_minutes)}</td>
              <td>{minutes(b.used_minutes)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
