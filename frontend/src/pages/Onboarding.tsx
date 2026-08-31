import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import LanguageSwitcher from '../components/LanguageSwitcher'
import Field from '../components/Field'

export default function Onboarding() {
  const { t, i18n } = useTranslation()
  const { refresh } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({
    name: '', legal_name: '', country_code: '', timezone: 'UTC', default_currency: 'USD',
  })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const update = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }))

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await ensureCsrf()
      await api.post('/onboarding/company', { ...form, default_locale: i18n.language })
      await refresh()
      navigate('/')
    } catch {
      setError(t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-screen">
      <form className="auth-card wide" onSubmit={submit}>
        <div className="auth-head">
          <h1>{t('onboarding.title')}</h1>
          <LanguageSwitcher />
        </div>
        <p className="muted">{t('onboarding.subtitle')}</p>
        {error && <p className="field-error">{error}</p>}
        <Field label={t('onboarding.company_name')} name="name" value={form.name} onChange={update('name')} required />
        <Field label={t('onboarding.legal_name')} name="legal_name" value={form.legal_name} onChange={update('legal_name')} />
        <div className="grid-2">
          <Field label={t('onboarding.country')} name="country_code" maxLength={2} value={form.country_code} onChange={update('country_code')} />
          <Field label={t('onboarding.timezone')} name="timezone" value={form.timezone} onChange={update('timezone')} />
        </div>
        <Field label={t('onboarding.currency')} name="default_currency" maxLength={3} value={form.default_currency} onChange={update('default_currency')} />
        <button className="btn-primary" type="submit" disabled={busy}>
          {busy ? t('onboarding.creating') : t('onboarding.create')}
        </button>
      </form>
    </div>
  )
}
