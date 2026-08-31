import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../lib/api'
import Field from '../components/Field'

export default function ForgotPassword() {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await ensureCsrf()
      const { data } = await api.post('/forgot-password', { email })
      setMessage(data.message ?? '')
    } catch {
      setMessage(t('common.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth-screen">
      <form className="auth-card" onSubmit={submit}>
        <h2>{t('auth.reset')}</h2>
        {message && <p className="notice">{message}</p>}
        <Field label={t('auth.email')} name="email" type="email" value={email}
          onChange={(e) => setEmail(e.target.value)} required />
        <button className="btn-primary" type="submit" disabled={busy}>{t('auth.send_reset_link')}</button>
        <div className="auth-links"><Link to="/login">{t('auth.login')}</Link></div>
      </form>
    </div>
  )
}
