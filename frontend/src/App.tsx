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
import Employees from './pages/org/Employees'
import EmployeeForm from './pages/org/EmployeeForm'
import EmployeeDetail from './pages/org/EmployeeDetail'
import Branches from './pages/org/Branches'
import Departments from './pages/org/Departments'
import Teams from './pages/org/Teams'
import JobTitles from './pages/org/JobTitles'
import MyAttendance from './pages/attendance/MyAttendance'
import AttendanceRecords from './pages/attendance/AttendanceRecords'
import AttendanceSchedules from './pages/attendance/AttendanceSchedules'
import AttendanceLocations from './pages/attendance/AttendanceLocations'
import AttendanceSettingsPage from './pages/attendance/AttendanceSettings'
import AttendanceCorrections from './pages/attendance/AttendanceCorrections'
import BillingLayout from './pages/billing/BillingLayout'
import BillingOverview from './pages/billing/BillingOverview'
import BillingSubscription from './pages/billing/BillingSubscription'
import BillingInvoices from './pages/billing/BillingInvoices'
import BillingPayments from './pages/billing/BillingPayments'
import BillingDetails from './pages/billing/BillingDetails'
import PlatformLogin from './pages/platform/PlatformLogin'
import PlatformLayout from './pages/platform/PlatformLayout'
import PlatformDashboard from './pages/platform/PlatformDashboard'
import PlatformAudit from './pages/platform/PlatformAudit'
import PlatformBilling from './pages/platform/PlatformBilling'
import PlatformPlans from './pages/platform/PlatformPlans'
import PlatformCoupons from './pages/platform/PlatformCoupons'
import PlatformBankAccounts from './pages/platform/PlatformBankAccounts'
import PlatformBankTransfers from './pages/platform/PlatformBankTransfers'

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
          <Route path="/employees" element={<Employees />} />
          <Route path="/employees/new" element={<EmployeeForm />} />
          <Route path="/employees/:id" element={<EmployeeDetail />} />
          <Route path="/branches" element={<Branches />} />
          <Route path="/departments" element={<Departments />} />
          <Route path="/teams" element={<Teams />} />
          <Route path="/job-titles" element={<JobTitles />} />
          <Route path="/attendance" element={<MyAttendance />} />
          <Route path="/attendance/records" element={<AttendanceRecords />} />
          <Route path="/attendance/schedules" element={<AttendanceSchedules />} />
          <Route path="/attendance/locations" element={<AttendanceLocations />} />
          <Route path="/attendance/corrections" element={<AttendanceCorrections />} />
          <Route path="/attendance/settings" element={<AttendanceSettingsPage />} />
          <Route path="/billing" element={<BillingLayout />}>
            <Route index element={<BillingOverview />} />
            <Route path="subscription" element={<BillingSubscription />} />
            <Route path="invoices" element={<BillingInvoices />} />
            <Route path="payments" element={<BillingPayments />} />
            <Route path="details" element={<BillingDetails />} />
          </Route>
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
        <Route path="plans" element={<PlatformPlans />} />
        <Route path="coupons" element={<PlatformCoupons />} />
        <Route path="bank-accounts" element={<PlatformBankAccounts />} />
        <Route path="transfers" element={<PlatformBankTransfers />} />
        <Route path="billing" element={<PlatformBilling />} />
        <Route path="audit" element={<PlatformAudit />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
