import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { api, ensureCsrf } from '../lib/api'
import LanguageSwitcher from './LanguageSwitcher'

export default function AppLayout() {
  const { t } = useTranslation()
  const { user, logout, can, refresh } = useAuth()
  const navigate = useNavigate()

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  async function resendVerification() {
    await ensureCsrf()
    await api.post('/email/verification-notification')
    await refresh()
  }

  // Nav visibility is a convenience only — the backend authorizes every call.
  const links = [
    { to: '/', label: t('nav.dashboard'), show: true, end: true },
    { to: '/employees', label: t('nav.employees'), show: can('employees.view') },
    { to: '/branches', label: t('nav.branches'), show: can('branches.view') },
    { to: '/departments', label: t('nav.departments'), show: can('departments.view') },
    { to: '/teams', label: t('nav.teams'), show: can('teams.view') },
    { to: '/job-titles', label: t('nav.job_titles'), show: can('job_titles.view') },
    { to: '/company', label: t('nav.company'), show: can('company.view') },
    { to: '/users', label: t('nav.users'), show: can('user.view') },
    { to: '/roles', label: t('nav.roles'), show: can('role.view') },
    { to: '/audit', label: t('nav.audit'), show: can('audit.view') },
    { to: '/profile', label: t('nav.profile'), show: true },
  ].filter((l) => l.show)

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">{t('app.name')}</div>
        <nav>
          {links.map((l) => (
            <NavLink key={l.to} to={l.to} end={l.end} className={({ isActive }) => (isActive ? 'active' : '')}>
              {l.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="main-column">
        <header className="topbar">
          <div className="company-name">{user?.active_tenant?.name}</div>
          <div className="topbar-right">
            <LanguageSwitcher />
            <span className="user-email">{user?.email}</span>
            <button type="button" className="btn-ghost" onClick={handleLogout}>
              {t('nav.logout')}
            </button>
          </div>
        </header>

        {user && !user.email_verified && (
          <div className="banner">
            <span>{t('auth.verify_email_notice')}</span>
            <button type="button" className="btn-link" onClick={resendVerification}>
              {t('auth.resend_verification')}
            </button>
          </div>
        )}

        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
