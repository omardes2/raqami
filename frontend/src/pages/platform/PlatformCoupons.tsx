import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type Coupon } from '../../billing/api'
import { platformBilling } from '../../billing/platformApi'

const BLANK = { code: '', name: '', type: 'percentage', percentage: '', amount_minor: '', currency: 'USD', max_redemptions: '', per_tenant_limit: '' }

export default function PlatformCoupons() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Coupon[]>([])
  const [form, setForm] = useState({ ...BLANK })
  const [error, setError] = useState<string | null>(null)

  async function load() { setRows(await platformBilling.coupons()) }
  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  async function create(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    const body: Record<string, unknown> = { code: form.code, name: form.name, type: form.type }
    if (form.type === 'percentage') body.percentage = Number(form.percentage)
    else { body.amount_minor = Number(form.amount_minor); body.currency = form.currency }
    if (form.max_redemptions) body.max_redemptions = Number(form.max_redemptions)
    if (form.per_tenant_limit) body.per_tenant_limit = Number(form.per_tenant_limit)
    try {
      await platformBilling.createCoupon(body as Partial<Coupon>)
      setForm({ ...BLANK })
      await load()
    } catch (err) {
      const e2 = err as { response?: { data?: { message?: string } } }
      setError(e2.response?.data?.message ?? t('common.error'))
    }
  }
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <h1>{t('platform.billing.coupons')}</h1>
      <table className="data-table">
        <thead><tr><th>{t('platform.billing.code')}</th><th>{t('platform.billing.type')}</th><th>{t('platform.billing.value')}</th><th>{t('platform.billing.redeemed')}</th><th>{t('platform.billing.status')}</th><th></th></tr></thead>
        <tbody>
          {rows.map((c) => (
            <tr key={c.id}>
              <td className="mono">{c.code}</td>
              <td>{c.type}</td>
              <td>{c.type === 'percentage' ? `${c.percentage}%` : `${(c.amount_minor ?? 0) / 100} ${c.currency}`}</td>
              <td>{c.redeemed_count}{c.max_redemptions ? ` / ${c.max_redemptions}` : ''}</td>
              <td><span className={`pill pill-${c.status}`}>{c.status}</span></td>
              <td>{c.status === 'active' && <button className="btn-link" onClick={() => platformBilling.archiveCoupon(c.id).then(load)}>{t('common.archive')}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>{t('platform.billing.new_coupon')}</h2>
      {error && <p className="notice error">{error}</p>}
      <form onSubmit={create} className="inline-form">
        <input placeholder={t('platform.billing.code')} value={form.code} onChange={set('code')} required />
        <input placeholder={t('platform.billing.name')} value={form.name} onChange={set('name')} required />
        <select value={form.type} onChange={set('type')}>
          <option value="percentage">percentage</option><option value="fixed_amount">fixed_amount</option>
        </select>
        {form.type === 'percentage'
          ? <input type="number" placeholder="%" value={form.percentage} onChange={set('percentage')} />
          : <><input type="number" placeholder={t('platform.billing.amount_minor')} value={form.amount_minor} onChange={set('amount_minor')} /><input placeholder="USD" maxLength={3} value={form.currency} onChange={set('currency')} /></>}
        <input type="number" placeholder={t('platform.billing.max_redemptions')} value={form.max_redemptions} onChange={set('max_redemptions')} />
        <input type="number" placeholder={t('platform.billing.per_tenant_limit')} value={form.per_tenant_limit} onChange={set('per_tenant_limit')} />
        <button className="btn-primary" type="submit">{t('common.create')}</button>
      </form>
    </div>
  )
}
