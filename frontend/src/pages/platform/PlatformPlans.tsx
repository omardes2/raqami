import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { money, type Plan } from '../../billing/api'
import { platformBilling } from '../../billing/platformApi'

const BLANK = {
  name: '', slug: '', monthly_price_minor: 0, annual_price_minor: 0, currency: 'USD',
  trial_days: 14, employee_limit: '', status: 'active', visibility: 'public',
}

export default function PlatformPlans() {
  const { t } = useTranslation()
  const [plans, setPlans] = useState<Plan[]>([])
  const [form, setForm] = useState({ ...BLANK })
  const [error, setError] = useState<string | null>(null)

  async function load() { setPlans(await platformBilling.plans()) }
  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  async function create(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await platformBilling.createPlan({
        ...form,
        monthly_price_minor: Number(form.monthly_price_minor),
        annual_price_minor: Number(form.annual_price_minor),
        trial_days: Number(form.trial_days),
        employee_limit: form.employee_limit === '' ? null : Number(form.employee_limit),
      } as Partial<Plan>)
      setForm({ ...BLANK })
      await load()
    } catch (err) {
      const e2 = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      setError(e2.response?.data?.message ?? Object.values(e2.response?.data?.errors ?? {})[0]?.[0] ?? t('common.error'))
    }
  }

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <h1>{t('platform.billing.plans')}</h1>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('platform.billing.plan_name')}</th><th>{t('billing.interval.monthly')}</th>
            <th>{t('billing.interval.annual')}</th><th>{t('platform.billing.employees')}</th>
            <th>{t('platform.billing.status')}</th><th></th>
          </tr>
        </thead>
        <tbody>
          {plans.map((p) => (
            <tr key={p.id}>
              <td>{p.name} <span className="muted">/{p.slug}</span></td>
              <td>{money(p.monthly_price_minor, p.currency)}</td>
              <td>{money(p.annual_price_minor, p.currency)}</td>
              <td>{p.employee_limit ?? '∞'}</td>
              <td><span className={`pill pill-${p.status}`}>{p.status}</span></td>
              <td>{p.status !== 'archived' && <button className="btn-link" onClick={() => platformBilling.archivePlan(p.id).then(load)}>{t('common.archive')}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>{t('platform.billing.new_plan')}</h2>
      {error && <p className="notice error">{error}</p>}
      <form onSubmit={create} className="inline-form">
        <input placeholder={t('platform.billing.plan_name')} value={form.name} onChange={set('name')} required />
        <input placeholder="slug" value={form.slug} onChange={set('slug')} required />
        <input type="number" placeholder={t('platform.billing.monthly_minor')} value={form.monthly_price_minor} onChange={set('monthly_price_minor')} />
        <input type="number" placeholder={t('platform.billing.annual_minor')} value={form.annual_price_minor} onChange={set('annual_price_minor')} />
        <input placeholder="USD" maxLength={3} value={form.currency} onChange={set('currency')} />
        <input type="number" placeholder={t('platform.billing.trial_days')} value={form.trial_days} onChange={set('trial_days')} />
        <input type="number" placeholder={t('platform.billing.employee_limit')} value={form.employee_limit} onChange={set('employee_limit')} />
        <select value={form.status} onChange={set('status')}>
          <option value="draft">draft</option><option value="active">active</option>
        </select>
        <button className="btn-primary" type="submit">{t('common.create')}</button>
      </form>
    </div>
  )
}
