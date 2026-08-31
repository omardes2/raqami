import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'

export default function Dashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <div>
      <h1>{t('nav.dashboard')}</h1>
      <p className="muted">{t('common.welcome')}, {user?.name}.</p>
      <div className="cards">
        <div className="card">
          <div className="card-label">{t('nav.company')}</div>
          <div className="card-value">{user?.active_tenant?.name}</div>
        </div>
        <div className="card">
          <div className="card-label">{t('roles.role')}</div>
          <div className="card-value">{user?.roles.join(', ') || '—'}</div>
        </div>
        <div className="card">
          <div className="card-label">{t('roles.permissions')}</div>
          <div className="card-value">{user?.permissions.length ?? 0}</div>
        </div>
      </div>
    </div>
  )
}
