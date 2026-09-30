import { Navigate, Outlet, createBrowserRouter, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from './AuthContext'
import { NAV_SECTIONS } from './nav'
import LoginPage from '../features/auth/LoginPage'
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
import PublicRegisterPage from '../features/registration/PublicRegisterPage'
import TrackRequestPage from '../features/registration/TrackRequestPage'
import PackagesHomePage from '../features/registration/PackagesHomePage'
import PaymentsHomePage from '../features/payments/PaymentsHomePage'
import { VerifyCertificatePage } from '@ahl/certificates-react'
import CertificatesHome from '../features/certificates/MobileCertificates'
import ExamsHomePage from '../features/exams/ExamsHomePage'
import ExamDetailPage from '../features/exams/ExamDetailPage'
import MyExamsPage from '../features/exams/MyExamsPage'
import ExamPlayerPage from '../features/exams/ExamPlayerPage'
import { LotteryDetailPage, LotteryListPage } from '../features/lottery/LotteryPages'
import UsersHomePage from '../features/users/UsersHomePage'
import SettingsPage from '../features/settings/SettingsPage'
import MasterDataPage from '../features/masterData/MasterDataPage'
import TermSetupPage from '../features/termSetup/TermSetupPage'
import ReportsHomePage from '../features/reports/ReportsHomePage'
import MessagesHomePage from '../features/messages/MessagesHomePage'
import TeachersHomePage from '../features/teachers/TeachersHomePage'
import HonorBoardPage from '../features/honor/HonorBoardPage'
import HonorDisplayPage from '../features/honor/HonorDisplayPage'
import MyEngagementPage from '../features/honor/MyEngagementPage'
import { CompetitionDetailPage, CompetitionsHomePage } from '../features/competitions/CompetitionPages'
import { ChallengeDetailPage } from '../features/competitions/ChallengePages'
import SessionDeliveryPage from '../features/messages/SessionDeliveryPage'
import AuditLogPage from '../features/audit/AuditLogPage'
import ParentHomePage from '../features/portal/ParentHomePage'
import StudentProgressPage from '../features/portal/StudentProgressPage'
import { ChildAttendancePage, ChildCertificatesPage, ChildMemorizationPage } from '../features/portal/ChildPages'
import InvoicesPage from '../features/portal/InvoicesPage'
import SchedulePage from '../features/portal/SchedulePage'
import MessagesPage from '../features/portal/MessagesPage'
import AccountPage from '../features/portal/AccountPage'
import FamilyOnly from '../features/portal/FamilyOnly'

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

/** Staff (anyone with dashboard.view) get the admin shell; guardians go to /my-children, students to /my-progress. */
function StaffOrFamily() {
  const { can, hasRole } = useAuth()
  if (can('dashboard.view')) return <AppLayout />
  return <Navigate to={hasRole('student') && !hasRole('guardian') ? '/my-progress' : '/my-children'} replace />
}

/** Student / guardian portal (3.4), in its own shell: [path, page]. */
const PORTAL: [string, React.ComponentType][] = [
  ['/my-children', ParentHomePage],
  ['/my-children/:id/attendance', ChildAttendancePage],
  ['/my-children/:id/memorization', ChildMemorizationPage],
  ['/my-children/:id/certificates', ChildCertificatesPage],
  ['/my-progress', StudentProgressPage],
  ['/my-progress/attendance', ChildAttendancePage],
  ['/my-progress/memorization', ChildMemorizationPage],
  ['/my-progress/certificates', ChildCertificatesPage],
  ['/my-invoices', InvoicesPage],
  ['/my-schedule', SchedulePage],
  ['/my-messages', MessagesPage],
  ['/my-account', AccountPage],
]

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
  packages: <PackagesHomePage />,
  payments: <PaymentsHomePage />,
  certificates: <CertificatesHome />,
  exams: <ExamsHomePage />,
  lottery: <LotteryListPage />,
  honor: <HonorBoardPage />,
  competitions: <CompetitionsHomePage />,
  audit: <AuditLogPage />,
  users: <UsersHomePage />,
  settings: <SettingsPage />,
  master_data: <MasterDataPage />,
  term_setup: <TermSetupPage />,
  reports: <ReportsHomePage />,
  messages: <MessagesHomePage />,
  teachers: <TeachersHomePage />,
}

/** Detail pages under a section: [path, permissions (any), element]. */
const DETAIL: [string, string[], React.ReactNode][] = [
  ['exams/:id', ['exams.view'], <ExamDetailPage />],
  ['lottery/:id', ['lottery.view'], <LotteryDetailPage />],
  ['competitions/challenges/:id', ['challenges.view'], <ChallengeDetailPage />],
  ['messages/sessions/:sessionId', ['messages.view', 'attendance.view'], <SessionDeliveryPage />],
  ['competitions/:id', ['competitions.view'], <CompetitionDetailPage />],
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
  // Public self-registration and request tracking (3.1), reachable with or without a session.
  { path: '/register', element: <PublicRegisterPage /> },
  { path: '/track', element: <TrackRequestPage /> },
  { path: '/track/:no', element: <TrackRequestPage /> },
  // Public certificate verification from the QR code (16).
  { path: '/verify/:token', element: <VerifyCertificatePage /> },
  // Public TV screen of the published honor board (13), needs the display key.
  { path: '/display/honor', element: <HonorDisplayPage /> },
  {
    element: <RequireAuth />,
    children: [
      // Student / guardian portal pages (outside the staff shell).
      { path: '/my/exams', element: <MyExamsPage /> },
      { path: '/my/exams/:id', element: <ExamPlayerPage /> },
      { path: '/my/honor', element: <MyEngagementPage /> },
      ...PORTAL.map(([path, Page]) => ({ path, element: <FamilyOnly><Page /></FamilyOnly> })),
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
