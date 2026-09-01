import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { billing, money, type Payment } from '../../billing/api'

export default function BillingPaymentsPage() {
  const { t } = useTranslation()
  const [payments, setPayments] = useState<Payment[]>([])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { billing.payments().then(setPayments) }, [])

  return (
    <table className="data-table">
      <thead>
        <tr>
          <th>{t('billing.payment.date')}</th>
          <th>{t('billing.payment.method')}</th>
          <th>{t('billing.payment.amount')}</th>
          <th>{t('billing.payment.reference')}</th>
          <th>{t('billing.payment.status')}</th>
        </tr>
      </thead>
      <tbody>
        {payments.map((p) => (
          <tr key={p.id}>
            <td>{p.paid_at ? new Date(p.paid_at).toLocaleDateString() : '—'}</td>
            <td>{t(`billing.method.${p.method}`)}</td>
            <td>{money(p.amount_minor, p.currency)}</td>
            <td>{p.reference ?? '—'}</td>
            <td><span className={`pill pill-${p.status}`}>{t(`billing.payment_status.${p.status}`)}</span></td>
          </tr>
        ))}
        {payments.length === 0 && <tr><td colSpan={5} className="muted">{t('billing.payment.none')}</td></tr>}
      </tbody>
    </table>
  )
}
