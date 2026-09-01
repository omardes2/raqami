import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { money, type BankTransfer } from '../../billing/api'
import { platformBilling } from '../../billing/platformApi'

/** Super Admin bank-transfer review queue (approve/reject). */
export default function PlatformBankTransfers() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<BankTransfer[]>([])
  const [status, setStatus] = useState('pending_review')
  const [busy, setBusy] = useState<string | null>(null)

  async function load() { setRows(await platformBilling.bankTransfers(status)) }
  useEffect(() => {
    // eslint-disable-next-line react/set-state-in-effect
    platformBilling.bankTransfers(status).then(setRows)
  }, [status])

  async function approve(id: string) {
    setBusy(id)
    try { await platformBilling.approveTransfer(id); await load() } finally { setBusy(null) }
  }
  async function reject(id: string) {
    const reason = window.prompt(t('platform.billing.reject_reason') ?? '') ?? ''
    setBusy(id)
    try { await platformBilling.rejectTransfer(id, reason); await load() } finally { setBusy(null) }
  }

  return (
    <div>
      <h1>{t('platform.billing.transfers')}</h1>
      <div className="interval-toggle">
        {['pending_review', 'approved', 'rejected', 'all'].map((s) => (
          <button key={s} className={status === s ? 'active' : ''} onClick={() => setStatus(s)}>{s}</button>
        ))}
      </div>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('platform.billing.submitted')}</th><th>{t('billing.invoice.number')}</th>
            <th>{t('billing.payment.amount')}</th><th>{t('billing.bank.reference')}</th>
            <th>{t('platform.billing.proof')}</th><th>{t('platform.billing.status')}</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td>{new Date(r.created_at).toLocaleDateString()}</td>
              <td className="mono">{r.invoice_id.slice(0, 8)}…</td>
              <td>{money(r.amount_minor, r.currency)}</td>
              <td>{r.transfer_reference ?? '—'}</td>
              <td><a className="btn-link" href={`/api/platform/bank-transfers/${r.id}/proof`} target="_blank" rel="noreferrer">{r.original_filename}</a></td>
              <td><span className={`pill pill-${r.status}`}>{r.status}</span></td>
              <td className="row-actions">
                {r.status === 'pending_review' && (
                  <>
                    <button className="btn-link" disabled={busy === r.id} onClick={() => approve(r.id)}>{t('platform.billing.approve')}</button>
                    <button className="btn-link" disabled={busy === r.id} onClick={() => reject(r.id)}>{t('platform.billing.reject')}</button>
                  </>
                )}
              </td>
            </tr>
          ))}
          {rows.length === 0 && <tr><td colSpan={7} className="muted">{t('common.none')}</td></tr>}
        </tbody>
      </table>
    </div>
  )
}
