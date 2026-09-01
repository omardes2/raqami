import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import { billing, money, type BankAccount, type Invoice } from '../../billing/api'

export default function BillingInvoicesPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const canSubmit = can('billing.bank_transfer.submit')
  const [invoices, setInvoices] = useState<Invoice[]>([])
  const [payFor, setPayFor] = useState<Invoice | null>(null)

  async function load() { setInvoices(await billing.invoices()) }
  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  return (
    <div>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('billing.invoice.number')}</th>
            <th>{t('billing.invoice.issued')}</th>
            <th>{t('billing.invoice.due')}</th>
            <th>{t('billing.invoice.total')}</th>
            <th>{t('billing.invoice.paid')}</th>
            <th>{t('billing.invoice.status')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((inv) => (
            <tr key={inv.id}>
              <td>{inv.invoice_number}</td>
              <td>{inv.issued_at ? new Date(inv.issued_at).toLocaleDateString() : '—'}</td>
              <td>{inv.due_at ? new Date(inv.due_at).toLocaleDateString() : '—'}</td>
              <td>{money(inv.total_minor, inv.currency)}</td>
              <td>{money(inv.amount_paid_minor, inv.currency)}</td>
              <td><span className={`pill pill-${inv.status}`}>{t(`billing.invoice_status.${inv.status}`)}</span></td>
              <td className="row-actions">
                <a className="btn-link" href={`/api/billing/invoices/${inv.id}/html`} target="_blank" rel="noreferrer">{t('billing.invoice.view')}</a>
                {canSubmit && inv.amount_due_minor > 0 && (
                  <button className="btn-link" onClick={() => setPayFor(inv)}>{t('billing.invoice.pay')}</button>
                )}
              </td>
            </tr>
          ))}
          {invoices.length === 0 && <tr><td colSpan={7} className="muted">{t('billing.invoice.none')}</td></tr>}
        </tbody>
      </table>

      {payFor && <BankTransferPanel invoice={payFor} onClose={() => setPayFor(null)} onDone={() => { setPayFor(null); load() }} />}
    </div>
  )
}

function BankTransferPanel({ invoice, onClose, onDone }: { invoice: Invoice; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [accounts, setAccounts] = useState<BankAccount[]>([])
  const [reference, setReference] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { billing.bankAccounts(invoice.currency).then(setAccounts) }, [invoice.currency])

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!file) { setError(t('billing.bank.proof_required')); return }
    setBusy(true)
    setError(null)
    const form = new FormData()
    form.append('invoice_id', invoice.id)
    form.append('amount_minor', String(invoice.amount_due_minor))
    form.append('currency', invoice.currency)
    form.append('transfer_reference', reference)
    form.append('proof', file)
    try {
      await billing.submitBankTransfer(form)
      onDone()
    } catch (e) {
      const err = e as { response?: { data?: { message?: string } } }
      setError(err.response?.data?.message ?? t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h3>{t('billing.bank.title')} — {invoice.invoice_number}</h3>
        <p className="muted">{t('billing.bank.amount_due')}: {money(invoice.amount_due_minor, invoice.currency)}</p>

        <h4>{t('billing.bank.instructions')}</h4>
        {accounts.length === 0 && <p className="muted">{t('billing.bank.no_accounts')}</p>}
        {accounts.map((a) => (
          <div key={a.id} className="bank-account">
            <strong>{a.bank_name}</strong> — {a.account_holder}<br />
            <span className="mono">{a.account_number}</span>{a.swift_code ? ` · ${a.swift_code}` : ''}
            {a.instructions && <p className="muted">{a.instructions}</p>}
          </div>
        ))}

        {error && <p className="notice error">{error}</p>}
        <form onSubmit={submit}>
          <label>{t('billing.bank.reference')}
            <input value={reference} onChange={(e) => setReference(e.target.value)} />
          </label>
          <label>{t('billing.bank.proof')}
            <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </label>
          <div className="row-actions">
            <button type="button" className="btn-ghost" onClick={onClose}>{t('common.cancel')}</button>
            <button type="submit" className="btn-primary" disabled={busy}>{t('billing.bank.submit')}</button>
          </div>
        </form>
      </div>
    </div>
  )
}
