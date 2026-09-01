import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { billing, money, type BillingOverview } from '../../billing/api'

export default function BillingOverviewPage() {
  const { t } = useTranslation()
  const [data, setData] = useState<BillingOverview | null>(null)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { billing.overview().then(setData) }, [])

  if (!data) return <p>{t('common.loading')}</p>

  const sub = data.subscription
  const usage = data.employee_usage

  return (
    <div className="cards">
      <section className="card">
        <h3>{t('billing.overview.plan')}</h3>
        {sub ? (
          <>
            <p className="big">{sub.plan?.name ?? '—'}</p>
            <p><span className={`pill pill-${sub.status}`}>{t(`billing.status.${sub.status}`)}</span></p>
            <p className="muted">{t('billing.overview.interval')}: {t(`billing.interval.${sub.billing_interval}`)}</p>
            {sub.trial_days_remaining != null && (
              <p className="muted">{t('billing.overview.trial_remaining', { days: sub.trial_days_remaining })}</p>
            )}
            {sub.current_period_end && (
              <p className="muted">{t('billing.overview.next_renewal')}: {new Date(sub.current_period_end).toLocaleDateString()}</p>
            )}
          </>
        ) : (
          <p className="muted">{t('billing.overview.no_subscription')}</p>
        )}
      </section>

      <section className="card">
        <h3>{t('billing.overview.employees')}</h3>
        <p className="big">{usage.used}{usage.limit != null ? ` / ${usage.limit}` : ''}</p>
        <p className="muted">
          {usage.limit == null ? t('billing.overview.unlimited') : t('billing.overview.remaining', { n: usage.remaining })}
        </p>
      </section>

      <section className="card">
        <h3>{t('billing.overview.balance')}</h3>
        <p className="big">{money(data.outstanding_balance_minor, data.currency)}</p>
        {data.outstanding_balance_minor > 0 && <p className="muted">{t('billing.overview.balance_due')}</p>}
      </section>
    </div>
  )
}
