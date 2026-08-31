import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import LanguageSwitcher from '../components/LanguageSwitcher'
import Field from '../components/Field'

export default function Register() {
  const { t, i18n } = useTranslation()
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const update = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }))

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await register({ ...form, locale: i18n.language })
      navigate('/onboarding')
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
        <h2>{t('auth.register')}</h2>
        {error && <p className="field-error">{error}</p>}
        <Field label={t('auth.name')} name="name" value={form.name} onChange={update('name')} required />
        <Field label={t('auth.email')} name="email" type="email" value={form.email} onChange={update('email')} required />
        <Field label={t('auth.password')} name="password" type="password" value={form.password}
          onChange={update('password')} required autoComplete="new-password" />
        <Field label={t('auth.confirm_password')} name="password_confirmation" type="password"
          value={form.password_confirmation} onChange={update('password_confirmation')} required autoComplete="new-password" />
        <button className="btn-primary" type="submit" disabled={busy}>{t('auth.register')}</button>
        <div className="auth-links">
          <span>{t('auth.have_account')} <Link to="/login">{t('auth.login')}</Link></span>
        </div>
      </form>
    </div>
  )
}
