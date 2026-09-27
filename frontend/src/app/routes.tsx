import { Navigate, Outlet, createBrowserRouter, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from './AuthContext'
import LoginPage from '../features/auth/LoginPage'
import HomePage from '../features/home/HomePage'

function FullScreenLoader() {
  const { t } = useTranslation()
  return <div className="grid min-h-screen place-items-center text-stone-500">{t('loading')}</div>
}

/** Only signed-in users; optional role list restricts further. */
function RequireAuth({ roles }: { roles?: string[] }) {
  const { user, loading, hasRole } = useAuth()
  const location = useLocation()
  if (loading) return <FullScreenLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (roles && !hasRole(...roles)) return <Navigate to="/" replace />
  return <Outlet />
}

function GuestOnly() {
  const { user, loading } = useAuth()
  if (loading) return <FullScreenLoader />
  return user ? <Navigate to="/" replace /> : <Outlet />
}

export const router = createBrowserRouter([
  { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
  { element: <RequireAuth />, children: [{ path: '/', element: <HomePage /> }] },
  { path: '*', element: <Navigate to="/" replace /> },
])
