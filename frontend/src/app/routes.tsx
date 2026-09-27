import { Navigate, Outlet, createBrowserRouter, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from './AuthContext'
import { NAV_SECTIONS } from './nav'
import LoginPage from '../features/auth/LoginPage'
import HomePage from '../features/home/HomePage'
import DashboardPage from '../features/dashboard/DashboardPage'
import ComingSoon from '../features/common/ComingSoon'
import AppLayout from '../layouts/AppLayout'

function FullScreenLoader() {
  const { t } = useTranslation()
  return <div className="grid min-h-screen place-items-center text-ink/50">{t('loading')}</div>
}

function RequireAuth() {
  const { user, loading } = useAuth()
  const location = useLocation()
  if (loading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

function GuestOnly() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  return user ? <Navigate to="/" replace /> : <Outlet />
}

/** Staff (anyone with dashboard.view) get the admin shell; students and guardians their own home. */
function StaffOrFamily() {
  const { can } = useAuth()
  return can('dashboard.view') ? <AppLayout /> : <HomePage />
}

/** A section the user has no permission for bounces back to the dashboard. */
function Section({ keyName, icon, permissions }: { keyName: string; icon: string; permissions: string[] }) {
  const { can } = useAuth()
  if (!can(...permissions)) return <Navigate to="/" replace />
  return <ComingSoon section={keyName} icon={icon} />
}

export const router = createBrowserRouter([
  { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
  {
    element: <RequireAuth />,
    children: [
      {
        path: '/',
        element: <StaffOrFamily />,
        children: [
          { index: true, element: <DashboardPage /> },
          ...NAV_SECTIONS.filter((s) => s.path !== '/').map((s) => ({
            path: s.path.slice(1),
            element: <Section keyName={s.key} icon={s.icon} permissions={s.permissions} />,
          })),
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
])
