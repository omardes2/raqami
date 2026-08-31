import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../../lib/api'
import LanguageSwitcher from '../../components/LanguageSwitcher'
import Field from '../../components/Field'

export default function PlatformLogin() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    try {
      await ensureCsrf()
      await api.post('/platform/login', { email, password })
      navigate('/platform')
    } catch {
      setError(t('common.error'))
    }
  }

  return (
    <div className="auth-screen platform">
      <form className="auth-card" onSubmit={submit}>
        <div className="auth-head">
          <h1>{t('platform.login_title')}</h1>
          <LanguageSwitcher />
        </div>
        {error && <p className="field-error">{error}</p>}
        <Field label={t('auth.email')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        <Field label={t('auth.password')} type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        <button className="btn-primary" type="submit">{t('auth.login')}</button>
      </form>
    </div>
  )
}
