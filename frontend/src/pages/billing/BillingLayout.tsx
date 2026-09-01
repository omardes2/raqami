import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'

/** Tenant billing portal shell with sub-navigation (spec §23). */
export default function BillingLayout() {
  const { t } = useTranslation()
  const { can } = useAuth()

  const tabs = [
    { to: '/billing', end: true, label: t('billing.nav.overview'), show: can('billing.view') },
    { to: '/billing/subscription', label: t('billing.nav.subscription'), show: can('billing.subscription.view') },
    { to: '/billing/invoices', label: t('billing.nav.invoices'), show: can('billing.invoices.view') },
    { to: '/billing/payments', label: t('billing.nav.payments'), show: can('billing.payments.view') },
    { to: '/billing/details', label: t('billing.nav.details'), show: can('billing.view') },
  ].filter((tab) => tab.show)

  return (
    <div>
      <h1>{t('billing.title')}</h1>
      <nav className="subnav">
        {tabs.map((tab) => (
          <NavLink key={tab.to} to={tab.to} end={tab.end} className={({ isActive }) => (isActive ? 'active' : '')}>
            {tab.label}
          </NavLink>
        ))}
      </nav>
      <div className="subview">
        <Outlet />
      </div>
    </div>
  )
}
