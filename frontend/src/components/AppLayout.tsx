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
    { to: '/attendance', label: t('nav.my_attendance'), show: true, end: true },
    { to: '/attendance/records', label: t('nav.attendance'), show: can('attendance.view') },
    { to: '/attendance/schedules', label: t('nav.schedules'), show: can('attendance.schedules.view') },
    { to: '/attendance/locations', label: t('nav.locations'), show: can('attendance.locations.manage') },
    { to: '/attendance/corrections', label: t('nav.corrections'), show: can('attendance.corrections.review') },
    { to: '/attendance/holidays', label: t('nav.holidays'), show: can('attendance.holidays.view') },
    { to: '/attendance/exceptions', label: t('nav.exceptions'), show: can('attendance.exceptions.view') },
    { to: '/attendance/overtime', label: t('nav.overtime'), show: can('attendance.overtime.view') },
    { to: '/attendance/anomalies', label: t('nav.anomalies'), show: can('attendance.anomalies.view') },
    { to: '/attendance/reports', label: t('nav.reports'), show: can('attendance.reports.view') },
    { to: '/attendance/settings', label: t('nav.attendance_settings'), show: can('attendance.settings.manage') },
    { to: '/leave', label: t('nav.my_leave'), show: true, end: true },
    { to: '/leave/requests', label: t('nav.leave_requests'), show: can('leave.view') },
    { to: '/leave/calendar', label: t('nav.leave_calendar'), show: can('leave.reports.view') },
    { to: '/leave/balances', label: t('nav.leave_balances'), show: can('leave.balances.view') },
    { to: '/leave/types', label: t('nav.leave_types'), show: can('leave.types.view') },
    { to: '/leave/policies', label: t('nav.leave_policies'), show: can('leave.policies.view') },
    { to: '/leave/reports', label: t('nav.leave_reports'), show: can('leave.reports.view') },
    { to: '/leave/settings', label: t('nav.leave_settings'), show: can('leave.settings.manage') },
    { to: '/tasks', label: t('nav.my_tasks'), show: true, end: true },
    { to: '/tasks/manage', label: t('nav.tasks'), show: can('tasks.view') },
    { to: '/projects', label: t('nav.projects'), show: can('projects.view') },
    { to: '/tasks/workload', label: t('nav.workload'), show: can('tasks.reports.view') },
    { to: '/tasks/reports', label: t('nav.task_reports'), show: can('tasks.reports.view') },
    { to: '/tasks/statuses', label: t('nav.task_statuses'), show: can('tasks.settings.manage') },
    { to: '/payroll/runs', label: t('nav.payroll_runs'), show: can('payroll.runs.view') },
    { to: '/payroll/periods', label: t('nav.payroll_periods'), show: can('payroll.runs.view') },
    { to: '/payroll/components', label: t('nav.payroll_components'), show: can('payroll.compensation.view') },
    { to: '/payroll/compensation', label: t('nav.payroll_compensation'), show: can('payroll.compensation.view') },
    { to: '/payroll/settings', label: t('nav.payroll_settings'), show: can('payroll.settings.manage') },
    { to: '/me/payslips', label: t('nav.my_payslips'), show: can('payroll.view_own'), end: true },
    { to: '/billing', label: t('nav.billing'), show: can('billing.view') },
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
