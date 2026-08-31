import { useEffect, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../../lib/api'
import LanguageSwitcher from '../../components/LanguageSwitcher'

interface Admin { id: string; name: string; email: string }

export default function PlatformLayout() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [admin, setAdmin] = useState<Admin | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/platform/me')
      .then(({ data }) => setAdmin(data))
      .catch(() => navigate('/platform/login'))
      .finally(() => setLoading(false))
  }, [navigate])

  async function logout() {
    await ensureCsrf()
    await api.post('/platform/logout')
    navigate('/platform/login')
  }

  if (loading) return <div className="center-screen">{t('common.loading')}</div>
  if (!admin) return null

  return (
    <div className="app-shell platform">
      <aside className="sidebar">
        <div className="brand">{t('platform.title')}</div>
        <nav>
          <NavLink to="/platform" end className={({ isActive }) => (isActive ? 'active' : '')}>{t('platform.tenants')}</NavLink>
          <NavLink to="/platform/audit" className={({ isActive }) => (isActive ? 'active' : '')}>{t('platform.audit')}</NavLink>
        </nav>
      </aside>
      <div className="main-column">
        <header className="topbar">
          <div className="company-name">{admin.name}</div>
          <div className="topbar-right">
            <LanguageSwitcher />
            <button type="button" className="btn-ghost" onClick={logout}>{t('nav.logout')}</button>
          </div>
        </header>
        <main className="content"><Outlet /></main>
      </div>
    </div>
  )
}
