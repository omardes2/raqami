import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import LanguageSwitcher from '../components/LanguageSwitcher'
import Field from '../components/Field'

export default function Login() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const user = await login(email, password)
      navigate(user.active_tenant ? '/' : '/onboarding')
    } catch {
      setError(t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-screen">
      <form className="auth-card" onSubmit={submit}>
        <div className="auth-head">
          <h1>{t('app.name')}</h1>
          <LanguageSwitcher />
        </div>
        <h2>{t('auth.login')}</h2>
        {error && <p className="field-error">{error}</p>}
        <Field label={t('auth.email')} name="email" type="email" value={email}
          onChange={(e) => setEmail(e.target.value)} required autoComplete="email" />
        <Field label={t('auth.password')} name="password" type="password" value={password}
          onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
        <button className="btn-primary" type="submit" disabled={busy}>
          {busy ? t('auth.logging_in') : t('auth.login')}
        </button>
        <div className="auth-links">
          <Link to="/forgot-password">{t('auth.forgot')}</Link>
          <span>{t('auth.no_account')} <Link to="/register">{t('auth.register')}</Link></span>
        </div>
      </form>
    </div>
  )
}
