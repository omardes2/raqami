import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, minutes, type LeavePolicy, type LeaveType } from '../../leave/api'

/** Admin CRUD for leave policies + scope assignments (company scope). */
export default function LeavePolicies() {
  const { t } = useTranslation()
  const [policies, setPolicies] = useState<LeavePolicy[]>([])
  const [types, setTypes] = useState<LeaveType[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({
    leave_type_id: '', name: '', effective_from: '', entitlement_method: 'fixed', entitlement_minutes: '4800',
    accrual_frequency: 'none', accrual_minutes: '0', consumption_basis: 'scheduled_minutes', nominal_day_minutes: '',
    count_holidays: false, count_non_working_days: false, carry_forward_enabled: false, carry_forward_max_minutes: '',
    approval_flow: 'manager',
  })

  const load = useCallback(async () => {
    const [p, ty] = await Promise.all([leave.policies(), leave.types()])
    setPolicies(p)
    setTypes(ty)
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await leave.createPolicy({
        ...form,
        entitlement_minutes: Number(form.entitlement_minutes),
        accrual_minutes: Number(form.accrual_minutes),
        nominal_day_minutes: form.nominal_day_minutes ? Number(form.nominal_day_minutes) : null,
        carry_forward_max_minutes: form.carry_forward_max_minutes ? Number(form.carry_forward_max_minutes) : null,
      })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function assignCompany(id: string) {
    setBusy(true)
    try {
      await leave.assignPolicy(id, { scope_type: 'company', effective_from: new Date().toISOString().slice(0, 10) })
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
      <h1>{t('leave.policies')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <section>
        <h2>{t('leave.new_policy')}</h2>
        <div className="form-grid">
          <label>{t('leave.type')}
            <select value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })}>
              <option value="">—</option>
              {types.map((ty) => <option key={ty.id} value={ty.id}>{ty.name}</option>)}
            </select>
          </label>
          <label>{t('leave.name')}<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label>{t('leave.effective_from')}<input type="date" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} /></label>
          <label>{t('leave.entitlement_method')}
            <select value={form.entitlement_method} onChange={(e) => setForm({ ...form, entitlement_method: e.target.value })}>
              {['none', 'fixed', 'accrual'].map((m) => <option key={m} value={m}>{m}</option>)}
            </select>
          </label>
          <label>{t('leave.entitlement_minutes')}<input type="number" value={form.entitlement_minutes} onChange={(e) => setForm({ ...form, entitlement_minutes: e.target.value })} /></label>
          <label>{t('leave.accrual_frequency')}
            <select value={form.accrual_frequency} onChange={(e) => setForm({ ...form, accrual_frequency: e.target.value })}>
              {['none', 'monthly', 'annual'].map((m) => <option key={m} value={m}>{m}</option>)}
            </select>
          </label>
          <label>{t('leave.accrual_minutes')}<input type="number" value={form.accrual_minutes} onChange={(e) => setForm({ ...form, accrual_minutes: e.target.value })} /></label>
          <label>{t('leave.consumption_basis')}
            <select value={form.consumption_basis} onChange={(e) => setForm({ ...form, consumption_basis: e.target.value })}>
              <option value="scheduled_minutes">{t('leave.basis_scheduled')}</option>
              <option value="nominal_calendar_day">{t('leave.basis_nominal')}</option>
            </select>
          </label>
          {form.consumption_basis === 'nominal_calendar_day' && (
            <label>{t('leave.nominal_day_minutes')}<input type="number" value={form.nominal_day_minutes} onChange={(e) => setForm({ ...form, nominal_day_minutes: e.target.value })} /></label>
          )}
          <label><input type="checkbox" checked={form.count_holidays} onChange={(e) => setForm({ ...form, count_holidays: e.target.checked })} /> {t('leave.count_holidays')}</label>
          <label><input type="checkbox" checked={form.count_non_working_days} onChange={(e) => setForm({ ...form, count_non_working_days: e.target.checked })} /> {t('leave.count_non_working')}</label>
          <label><input type="checkbox" checked={form.carry_forward_enabled} onChange={(e) => setForm({ ...form, carry_forward_enabled: e.target.checked })} /> {t('leave.carry_forward')}</label>
          {form.carry_forward_enabled && (
            <label>{t('leave.carry_max')}<input type="number" value={form.carry_forward_max_minutes} onChange={(e) => setForm({ ...form, carry_forward_max_minutes: e.target.value })} /></label>
          )}
          <label>{t('leave.approval_flow')}
            <select value={form.approval_flow} onChange={(e) => setForm({ ...form, approval_flow: e.target.value })}>
              {['none', 'manager', 'hr', 'manager_then_hr'].map((m) => <option key={m} value={m}>{m}</option>)}
            </select>
          </label>
        </div>
        {(form.count_holidays || form.count_non_working_days) && form.consumption_basis !== 'nominal_calendar_day' && (
          <p className="hint">{t('leave.count_days_hint')}</p>
        )}
        <button type="button" onClick={() => void create()} disabled={busy || !form.leave_type_id || !form.name || !form.effective_from}>{t('common.create')}</button>
      </section>
      <table>
        <thead>
          <tr><th>{t('leave.name')}</th><th>{t('leave.entitlement_method')}</th><th>{t('leave.consumption_basis')}</th><th>{t('leave.scopes')}</th><th></th></tr>
        </thead>
        <tbody>
          {policies.map((p) => (
            <tr key={p.id}>
              <td>{p.name}</td>
              <td>{p.entitlement_method} ({minutes(p.entitlement_minutes)})</td>
              <td>{p.consumption_basis}</td>
              <td>{(p.assignments ?? []).map((a) => a.scope_type).join(', ') || '—'}</td>
              <td><button type="button" onClick={() => void assignCompany(p.id)} disabled={busy}>{t('leave.assign_company')}</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
