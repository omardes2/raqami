import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import Field from '../components/Field'

interface Company {
  name: string; legal_name: string | null; country_code: string | null
  timezone: string; default_currency: string; default_locale: string; status: string
}

export default function CompanyPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [company, setCompany] = useState<Company | null>(null)
  const [saved, setSaved] = useState(false)
  const editable = can('company.update')

  useEffect(() => { api.get('/company').then(({ data }) => setCompany(data)) }, [])

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!company) return
    const { data } = await api.patch('/company', {
      name: company.name, legal_name: company.legal_name,
      country_code: company.country_code, timezone: company.timezone,
      default_currency: company.default_currency, default_locale: company.default_locale,
    })
    setCompany(data)
    setSaved(true)
  }

  if (!company) return <p>{t('common.loading')}</p>
  const set = (k: keyof Company) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setCompany({ ...company, [k]: e.target.value })

  return (
    <div className="narrow">
      <h1>{t('company.title')}</h1>
      {saved && <p className="notice">{t('company.saved')}</p>}
      <form onSubmit={submit}>
        <Field label={t('company.name')} value={company.name} onChange={set('name')} disabled={!editable} />
        <Field label={t('company.legal_name')} value={company.legal_name ?? ''} onChange={set('legal_name')} disabled={!editable} />
        <div className="grid-2">
          <Field label={t('company.country')} value={company.country_code ?? ''} maxLength={2} onChange={set('country_code')} disabled={!editable} />
          <Field label={t('company.timezone')} value={company.timezone} onChange={set('timezone')} disabled={!editable} />
        </div>
        <Field label={t('company.currency')} value={company.default_currency} maxLength={3} onChange={set('default_currency')} disabled={!editable} />
        {editable && <button className="btn-primary" type="submit">{t('company.save')}</button>}
      </form>
    </div>
  )
}
