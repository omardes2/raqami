import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { platformBilling, type PlatformBankAccount } from '../../billing/platformApi'

const BLANK = { label: '', bank_name: '', account_holder: '', account_number: '', swift_code: '', currency: 'USD', country_code: '', instructions: '', internal_notes: '' }

export default function PlatformBankAccounts() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<PlatformBankAccount[]>([])
  const [form, setForm] = useState({ ...BLANK })

  async function load() { setRows(await platformBilling.bankAccounts()) }
  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { load() }, [])

  async function create(e: React.FormEvent) {
    e.preventDefault()
    await platformBilling.createBankAccount(form)
    setForm({ ...BLANK })
    await load()
  }
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })

  return (
    <div>
      <h1>{t('platform.billing.bank_accounts')}</h1>
      <table className="data-table">
        <thead><tr><th>{t('platform.billing.label')}</th><th>{t('billing.bank.bank')}</th><th>{t('billing.invoice.total')}</th><th>{t('platform.billing.status')}</th><th></th></tr></thead>
        <tbody>
          {rows.map((a) => (
            <tr key={a.id}>
              <td>{a.label}</td>
              <td>{a.bank_name} <span className="mono">{a.account_number}</span></td>
              <td>{a.currency}</td>
              <td><span className={`pill pill-${a.status}`}>{a.status}</span></td>
              <td>{a.status === 'active' && <button className="btn-link" onClick={() => platformBilling.archiveBankAccount(a.id).then(load)}>{t('common.archive')}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h2>{t('platform.billing.new_bank_account')}</h2>
      <form onSubmit={create} className="inline-form">
        <input placeholder={t('platform.billing.label')} value={form.label} onChange={set('label')} required />
        <input placeholder={t('billing.bank.bank')} value={form.bank_name} onChange={set('bank_name')} required />
        <input placeholder={t('billing.bank.holder')} value={form.account_holder} onChange={set('account_holder')} required />
        <input placeholder="IBAN / account" value={form.account_number} onChange={set('account_number')} required />
        <input placeholder="SWIFT" value={form.swift_code} onChange={set('swift_code')} />
        <input placeholder="USD" maxLength={3} value={form.currency} onChange={set('currency')} />
        <input placeholder={t('billing.bank.instructions')} value={form.instructions} onChange={set('instructions')} />
        <button className="btn-primary" type="submit">{t('common.create')}</button>
      </form>
    </div>
  )
}
