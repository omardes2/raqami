import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { payroll, type PayrollComponent } from '../../payroll/api'

/** Tenant compensation component catalog (payroll.components.manage). */
export default function PayrollComponents() {
  const { t } = useTranslation()
  const [items, setItems] = useState<PayrollComponent[]>([])
  const [form, setForm] = useState({ code: '', name: '', type: 'earning', calculation_mode: 'fixed' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setItems(await payroll.components())
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await payroll.createComponent({ ...form })
      setForm({ code: '', name: '', type: 'earning', calculation_mode: 'fixed' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function toggleActive(c: PayrollComponent) {
    try {
      await payroll.updateComponent(c.id, { active: !c.active })
      await load()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  return (
    <div className="page">
      <h1>{t('nav.payroll_components')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="card">
        <input placeholder={t('payroll.component_code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
        <input placeholder={t('payroll.component_name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
          {['earning', 'deduction'].map((v) => <option key={v} value={v}>{t(`payroll.type_${v}`)}</option>)}
        </select>
        <select value={form.calculation_mode} onChange={(e) => setForm({ ...form, calculation_mode: e.target.value })}>
          {['fixed', 'percent_of_base'].map((v) => <option key={v} value={v}>{t(`payroll.mode_${v}`)}</option>)}
        </select>
        <button type="button" onClick={() => void create()} disabled={busy || !form.code || !form.name}>{t('common.create')}</button>
      </div>
      <table>
        <thead><tr><th>{t('payroll.component_code')}</th><th>{t('payroll.component_name')}</th><th>{t('payroll.component_type')}</th><th>{t('payroll.component_mode')}</th><th>{t('payroll.component_active')}</th><th /></tr></thead>
        <tbody>
          {items.map((c) => (
            <tr key={c.id}>
              <td>{c.code}</td><td>{c.name}</td><td>{t(`payroll.type_${c.type}`)}</td><td>{t(`payroll.mode_${c.calculation_mode}`)}</td><td>{c.active ? '✓' : ''}</td>
              <td><button type="button" className="btn-ghost" onClick={() => void toggleActive(c)}>{c.active ? t('payroll.deactivate') : t('payroll.activate')}</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
