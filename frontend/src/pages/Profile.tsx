import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../lib/api'
import { applyLocale, SUPPORTED_LOCALES } from '../i18n'
import { useAuth } from '../auth/AuthContext'
import Field from '../components/Field'

export default function Profile() {
  const { t } = useTranslation()
  const { user, refresh } = useAuth()
  const [name, setName] = useState(user?.name ?? '')
  const [locale, setLocale] = useState(user?.locale ?? 'en')
  const [timezone, setTimezone] = useState(user?.timezone ?? 'UTC')
  const [saved, setSaved] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    await api.patch('/me', { name, locale, timezone })
    applyLocale(locale)
    await refresh()
    setSaved(true)
  }

  return (
    <div className="narrow">
      <h1>{t('profile.title')}</h1>
      {saved && <p className="notice">{t('profile.saved')}</p>}
      <form onSubmit={submit}>
        <Field label={t('profile.name')} name="name" value={name} onChange={(e) => setName(e.target.value)} />
        <div className="field">
          <label htmlFor="locale">{t('profile.language')}</label>
          <select id="locale" value={locale} onChange={(e) => setLocale(e.target.value)}>
            {SUPPORTED_LOCALES.map((l) => <option key={l} value={l}>{l === 'ar' ? 'العربية' : 'English'}</option>)}
          </select>
        </div>
        <Field label={t('profile.timezone')} name="timezone" value={timezone} onChange={(e) => setTimezone(e.target.value)} />
        <button className="btn-primary" type="submit">{t('profile.save')}</button>
      </form>
    </div>
  )
}
