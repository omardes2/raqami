import { Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import AppLayout from './components/AppLayout'
import Login from './pages/Login'
import Register from './pages/Register'
import ForgotPassword from './pages/ForgotPassword'
import Onboarding from './pages/Onboarding'
import Dashboard from './pages/Dashboard'
import CompanyPage from './pages/Company'
import Users from './pages/Users'
import Roles from './pages/Roles'
import Audit from './pages/Audit'
import Profile from './pages/Profile'
import PlatformLogin from './pages/platform/PlatformLogin'
import PlatformLayout from './pages/platform/PlatformLayout'
import PlatformDashboard from './pages/platform/PlatformDashboard'
import PlatformAudit from './pages/platform/PlatformAudit'

export default function App() {
  return (
    <Routes>
      {/* Public auth */}
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />

      {/* Authenticated, pre-tenant (onboarding) */}
      <Route element={<ProtectedRoute />}>
        <Route path="/onboarding" element={<Onboarding />} />
      </Route>

      {/* Authenticated tenant application */}
      <Route element={<ProtectedRoute requireTenant />}>
        <Route element={<AppLayout />}>
          <Route index element={<Dashboard />} />
          <Route path="/company" element={<CompanyPage />} />
          <Route path="/users" element={<Users />} />
          <Route path="/roles" element={<Roles />} />
          <Route path="/audit" element={<Audit />} />
          <Route path="/profile" element={<Profile />} />
        </Route>
      </Route>

      {/* Super Admin portal (separate identity/guard) */}
      <Route path="/platform/login" element={<PlatformLogin />} />
      <Route path="/platform" element={<PlatformLayout />}>
        <Route index element={<PlatformDashboard />} />
        <Route path="audit" element={<PlatformAudit />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
