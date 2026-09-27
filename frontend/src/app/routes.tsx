import { Navigate, Outlet, createBrowserRouter, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from './AuthContext'
import { NAV_SECTIONS } from './nav'
import LoginPage from '../features/auth/LoginPage'
import HomePage from '../features/home/HomePage'
import DashboardPage from '../features/dashboard/DashboardPage'
import ComingSoon from '../features/common/ComingSoon'
import StudentsListPage from '../features/students/StudentsListPage'
import StudentProfilePage from '../features/students/StudentProfilePage'
import AttendanceDayPage from '../features/attendance/AttendanceDayPage'
import AttendanceSheetPage from '../features/attendance/AttendanceSheetPage'
import EvaluationHomePage from '../features/evaluation/EvaluationHomePage'
import EvaluationSheetPage from '../features/evaluation/EvaluationSheetPage'
import LessonsHomePage from '../features/lessons/LessonsHomePage'
import LessonDetailPage from '../features/lessons/LessonDetailPage'
import HallCalendarPage from '../features/lessons/HallCalendarPage'
import AppLayout from '../layouts/AppLayout'
import { StarSpinner } from '../components/ornaments'
import OrnamentsDemo from '../features/design/OrnamentsDemo'
import QuickEnrollPage from '../features/enrollment/QuickEnrollPage'

function FullScreenLoader() {
  const { t } = useTranslation()
  return (
    <div className="grid min-h-screen place-items-center text-ink/60">
      <StarSpinner className="size-10 text-brand-600" label={t('loading')} />
    </div>
  )
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

/** Guards a built page by the same permissions as its sidebar entry. */
function Guard({ permissions, children }: { permissions: string[]; children: React.ReactNode }) {
  const { can } = useAuth()
  return can(...permissions) ? <>{children}</> : <Navigate to="/" replace />
}

/** Screens built so far; every other section shows the placeholder. */
const BUILT: Record<string, React.ReactNode> = {
  students: <StudentsListPage />,
  enrollment: <QuickEnrollPage />,
  attendance: <AttendanceDayPage />,
  evaluation: <EvaluationHomePage />,
  lessons: <LessonsHomePage />,
}

/** Detail pages under a section: [path, permissions (any), element]. */
const DETAIL: [string, string[], React.ReactNode][] = [
  ['students/:id', ['students.view'], <StudentProfilePage />],
  ['attendance/:sessionId', ['attendance.view', 'attendance.record'], <AttendanceSheetPage />],
  ['evaluation/:sessionId', ['evaluations.record'], <EvaluationSheetPage />],
  ['lessons/halls/:id', ['locations.view', 'lessons.view'], <HallCalendarPage />],
  ['lessons/:id', ['lessons.view'], <LessonDetailPage />],
]

/** A section the user has no permission for bounces back to the dashboard. */
function Section({ keyName, icon, permissions }: { keyName: string; icon: string; permissions: string[] }) {
  const { can } = useAuth()
  if (!can(...permissions)) return <Navigate to="/" replace />
  return <ComingSoon section={keyName} icon={icon} />
}

export const router = createBrowserRouter([
  { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
  // Public design reference for the ornament system (no data, no login).
  { path: '/design/ornaments', element: <OrnamentsDemo /> },
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
            element: BUILT[s.key] ? <Guard permissions={s.permissions}>{BUILT[s.key]}</Guard> : <Section keyName={s.key} icon={s.icon} permissions={s.permissions} />,
          })),
          ...DETAIL.map(([path, perms, el]) => ({ path, element: <Guard permissions={perms}>{el}</Guard> })),
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
])
