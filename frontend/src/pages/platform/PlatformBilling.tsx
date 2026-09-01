import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { money, type Invoice, type Payment, type Subscription } from '../../billing/api'
import { platformBilling } from '../../billing/platformApi'

type Tab = 'subscriptions' | 'invoices' | 'payments'

/** Super Admin cross-tenant billing lists + manual/cash payment recording. */
export default function PlatformBilling() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('subscriptions')
  const [subs, setSubs] = useState<Subscription[]>([])
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [payments, setPayments] = useState<Payment[]>([])

  async function load() {
    const [s, i, p] = await Promise.all([platformBilling.subscriptions(), platformBilling.invoices(), platformBilling.payments()])
    setSubs(s); setInvoices(i); setPayments(p)
  }
  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  return (
    <div>
      <h1>{t('platform.billing.title')}</h1>
      <div className="interval-toggle">
        {(['subscriptions', 'invoices', 'payments'] as Tab[]).map((x) => (
          <button key={x} className={tab === x ? 'active' : ''} onClick={() => setTab(x)}>{t(`platform.billing.${x}`)}</button>
        ))}
      </div>

      {tab === 'subscriptions' && (
        <table className="data-table">
          <thead><tr><th>{t('platform.billing.plan_name')}</th><th>{t('platform.billing.status')}</th><th>{t('billing.overview.interval')}</th><th>{t('billing.overview.next_renewal')}</th></tr></thead>
          <tbody>
            {subs.map((s) => (
              <tr key={s.id}>
                <td>{s.plan?.name ?? '—'}</td>
                <td><span className={`pill pill-${s.status}`}>{s.status}</span></td>
                <td>{s.billing_interval}</td>
                <td>{s.current_period_end ? new Date(s.current_period_end).toLocaleDateString() : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {tab === 'invoices' && (
        <table className="data-table">
          <thead><tr><th>{t('billing.invoice.number')}</th><th>{t('billing.invoice.total')}</th><th>{t('billing.invoice.paid')}</th><th>{t('platform.billing.status')}</th><th></th></tr></thead>
          <tbody>
            {invoices.map((inv) => (
              <tr key={inv.id}>
                <td>{inv.invoice_number}</td>
                <td>{money(inv.total_minor, inv.currency)}</td>
                <td>{money(inv.amount_paid_minor, inv.currency)}</td>
                <td><span className={`pill pill-${inv.status}`}>{inv.status}</span></td>
                <td>{inv.amount_due_minor > 0 && <ManualPay invoice={inv} onDone={load} />}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {tab === 'payments' && (
        <table className="data-table">
          <thead><tr><th>{t('billing.payment.date')}</th><th>{t('billing.payment.method')}</th><th>{t('billing.payment.amount')}</th><th>{t('platform.billing.status')}</th></tr></thead>
          <tbody>
            {payments.map((p) => (
              <tr key={p.id}>
                <td>{p.paid_at ? new Date(p.paid_at).toLocaleDateString() : '—'}</td>
                <td>{p.method}</td>
                <td>{money(p.amount_minor, p.currency)}</td>
                <td><span className={`pill pill-${p.status}`}>{p.status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

function ManualPay({ invoice, onDone }: { invoice: Invoice; onDone: () => void }) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)

  async function record() {
    if (!window.confirm(t('platform.billing.confirm_manual') ?? '')) return
    setBusy(true)
    try {
      // tenant_id is required; the platform invoice list embeds it via subscription context.
      await platformBilling.recordManual({
        tenant_id: invoice.tenant_id ?? '',
        invoice_id: invoice.id,
        amount_minor: invoice.amount_due_minor,
        currency: invoice.currency,
        method: 'cash',
      })
      onDone()
    } finally { setBusy(false) }
  }

  return <button className="btn-link" disabled={busy} onClick={record}>{t('platform.billing.record_cash')}</button>
}
