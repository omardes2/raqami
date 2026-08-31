import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthContext'

/** Requires an authenticated tenant user; sends unverified onboarding to /onboarding. */
export default function ProtectedRoute({ requireTenant = false }: { requireTenant?: boolean }) {
  const { user, loading } = useAuth()
  const { t } = useTranslation()
  const location = useLocation()

  if (loading) return <div className="center-screen">{t('common.loading')}</div>
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />
  if (requireTenant && !user.active_tenant) return <Navigate to="/onboarding" replace />

  return <Outlet />
}
