import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'
import { reports, type DashboardData } from '../reports/api'

/**
 * Sprint 8A Phase 2: company dashboard. Renders ONLY the KPI cards the backend
 * returns — an unauthorized or out-of-scope card is simply absent (never a zero
 * or a placeholder). No money is shown here (the payroll card is status only).
 */
export default function Dashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [kpi, setKpi] = useState<DashboardData | null>(null)
  const [error, setError] = useState<string | null>(null)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => {
    let active = true
    reports.companyDashboard()
      .then((r) => { if (active) setKpi(r.data) })
      .catch((e) => { if (active) setError((e as Error).message) })
    return () => { active = false }
  }, [])

  return (
    <div>
      <h1>{t('nav.dashboard')}</h1>
      <p className="muted">{t('common.welcome')}, {user?.name}.</p>
      {error && <p className="error" role="alert">{error}</p>}

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

      {kpi && (
        <div className="cards">
          {kpi.organization && (
            <div className="card">
              <div className="card-label">{t('dashboard.active_employees')}</div>
              <div className="card-value">{kpi.organization.active_employees}</div>
            </div>
          )}
          {kpi.attendance && (
            <div className="card">
              <div className="card-label">{t('dashboard.attendance_today')}</div>
              <div className="card-value">{kpi.attendance.present}</div>
              <div className="muted">
                {t('dashboard.present')}: {kpi.attendance.present} ·{' '}
                {t('dashboard.absent')}: {kpi.attendance.absent} ·{' '}
                {t('dashboard.on_leave')}: {kpi.attendance.on_leave}
              </div>
              <div className="muted">{kpi.attendance.date}</div>
            </div>
          )}
          {kpi.leave && (
            <div className="card">
              <div className="card-label">{t('dashboard.pending_leave')}</div>
              <div className="card-value">{kpi.leave.pending_requests}</div>
            </div>
          )}
          {kpi.tasks && (
            <div className="card">
              <div className="card-label">{t('dashboard.overdue_tasks')}</div>
              <div className="card-value">{kpi.tasks.overdue}</div>
            </div>
          )}
          {kpi.payroll && (
            <div className="card">
              <div className="card-label">{t('dashboard.latest_payroll')}</div>
              <div className="card-value">{kpi.payroll.latest_period_label ?? '—'}</div>
              <div className="muted">
                {t('dashboard.period_status')}: {kpi.payroll.latest_period_status
                  ? t(`reports.payroll.period_status.${kpi.payroll.latest_period_status}`, kpi.payroll.latest_period_status)
                  : '—'}
              </div>
              <div className="muted">
                {t('dashboard.run_status')}: {kpi.payroll.latest_run_status
                  ? t(`reports.payroll.run_status.${kpi.payroll.latest_run_status}`, kpi.payroll.latest_run_status)
                  : '—'}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
