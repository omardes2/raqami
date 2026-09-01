import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import { billing, money, type Plan, type Subscription } from '../../billing/api'

export default function BillingSubscriptionPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const canChange = can('billing.subscription.change')
  const [sub, setSub] = useState<Subscription | null>(null)
  const [plans, setPlans] = useState<Plan[]>([])
  const [interval, setInterval] = useState<'monthly' | 'annual'>('monthly')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function load() {
    const [s, p] = await Promise.all([billing.subscription(), billing.plans()])
    setSub(s)
    setPlans(p)
    if (s) setInterval(s.billing_interval as 'monthly' | 'annual')
  }

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  async function act(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await fn()
      await load()
    } catch (e) {
      const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      setError(err.response?.data?.message ?? Object.values(err.response?.data?.errors ?? {})[0]?.[0] ?? t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  const choose = (plan: Plan) =>
    act(() => (sub ? billing.changePlan({ plan_id: plan.id, interval }) : billing.subscribe({ plan_id: plan.id, interval })))

  return (
    <div>
      {error && <p className="notice error">{error}</p>}

      {sub && (
        <section className="card" style={{ marginBottom: 16 }}>
          <h3>{t('billing.subscription.current')}</h3>
          <p className="big">{sub.plan?.name} · <span className={`pill pill-${sub.status}`}>{t(`billing.status.${sub.status}`)}</span></p>
          {sub.cancel_at_period_end && <p className="notice">{t('billing.subscription.cancel_scheduled')}</p>}
          {canChange && (
            <div className="row-actions">
              {sub.cancel_at_period_end
                ? <button className="btn-primary" disabled={busy} onClick={() => act(billing.resume)}>{t('billing.subscription.resume')}</button>
                : <button className="btn-ghost" disabled={busy} onClick={() => act(billing.cancel)}>{t('billing.subscription.cancel')}</button>}
            </div>
          )}
        </section>
      )}

      <div className="interval-toggle">
        <button className={interval === 'monthly' ? 'active' : ''} onClick={() => setInterval('monthly')}>{t('billing.interval.monthly')}</button>
        <button className={interval === 'annual' ? 'active' : ''} onClick={() => setInterval('annual')}>{t('billing.interval.annual')}</button>
      </div>

      <div className="plan-grid">
        {plans.map((plan) => {
          const price = interval === 'annual' ? plan.annual_price_minor : plan.monthly_price_minor
          const current = sub?.plan_id === plan.id
          return (
            <div key={plan.id} className={`plan-card${plan.is_featured ? ' featured' : ''}${current ? ' current' : ''}`}>
              <h4>{plan.name}</h4>
              <p className="price">{money(price, plan.currency)}<span className="muted">/{t(`billing.interval.${interval}`)}</span></p>
              {plan.description && <p className="muted">{plan.description}</p>}
              <p className="muted">{plan.employee_limit == null ? t('billing.plan.unlimited_employees') : t('billing.plan.employee_limit', { n: plan.employee_limit })}</p>
              {plan.trial_days > 0 && <p className="muted">{t('billing.plan.trial', { days: plan.trial_days })}</p>}
              {canChange && (
                current
                  ? <button className="btn-ghost" disabled>{t('billing.plan.current')}</button>
                  : <button className="btn-primary" disabled={busy} onClick={() => choose(plan)}>{sub ? t('billing.plan.switch') : t('billing.plan.choose')}</button>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
