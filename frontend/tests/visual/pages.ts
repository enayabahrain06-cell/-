/**
 * The 27 audited staff pages, grouped by the page families in `.ui/skills/visual-qa/SKILL.md`.
 *
 * `master: true` pages are screenshotted at every width; the others at MEMBER_WIDTHS only.
 * Layout checks (overflow, clipping, grids, wrapping…) run on every page at every width.
 */
export type Ids = Partial<Record<'lesson' | 'hall' | 'student' | 'exam' | 'session' | 'sessionDate' | 'lottery' | 'competition', string>>

export type VisualPage = {
  name: string
  family: 'list' | 'detail' | 'dashboard' | 'form'
  master?: boolean
  /** Path, or a builder that returns null when the record it needs does not exist in this database. */
  path: string | ((ids: Ids) => string | null)
  /** Freeze the browser clock at noon (Bahrain) on the pinned session date, so "today" logic is stable. */
  pinClock?: boolean
  /** Selectors painted over in screenshots: server-side "today" data that changes every day. Layout is still compared. */
  mask?: string[]
}

export const WIDTHS = [320, 375, 390, 414, 480, 768, 1024, 1280, 1440] as const
/** Phone, tablet and desktop: enough to catch a family member drifting from its master. */
export const MEMBER_WIDTHS: readonly number[] = [375, 768, 1280]

/** Dashboard parts that follow the server's date: header date and refresh time, KPI values, and the card bodies. */
const DASHBOARD_MASK = [
  'main header p',
  'main header [class*="tabular-nums"]',
  'main section.grid.grid-cols-2 > *',
  '[aria-labelledby="alerts-title"] > :not(h2)',
  '[aria-labelledby="today-title"] > :not(h2)',
  '[aria-labelledby="attendance-chart-title"] > :not(:first-child)',
  '[aria-labelledby="activity-title"] > :not(h2)',
  '[aria-labelledby="upcoming-title"] > :not(h2)',
]

export const PAGES: VisualPage[] = [
  // Dashboard family
  // The dashboard is built from the server's "today", so its date-driven text is masked (see DASHBOARD_MASK).
  { name: 'dashboard', family: 'dashboard', master: true, path: '/', mask: DASHBOARD_MASK },
  { name: 'reports', family: 'dashboard', path: '/reports' },

  // List / home family (master: StudentsListPage)
  { name: 'students', family: 'list', master: true, path: '/students' },
  { name: 'lessons', family: 'list', path: '/lessons' },
  { name: 'teachers', family: 'list', path: '/teachers' },
  { name: 'packages', family: 'list', path: '/packages' },
  { name: 'payments', family: 'list', path: '/payments' },
  { name: 'exams', family: 'list', path: '/exams' },
  { name: 'evaluation', family: 'list', pinClock: true, path: (ids) => (ids.sessionDate ? `/evaluation?date=${ids.sessionDate}` : '/evaluation') },
  { name: 'certificates', family: 'list', path: '/certificates' },
  { name: 'lottery', family: 'list', path: '/lottery' },
  { name: 'honor', family: 'list', path: '/honor' },
  { name: 'competitions', family: 'list', path: '/competitions' },
  { name: 'messages', family: 'list', path: '/messages' },
  { name: 'users', family: 'list', path: '/users' },
  { name: 'settings', family: 'list', path: '/settings' },
  { name: 'audit', family: 'list', path: '/audit' },
  // The day view is pinned to a date that has sessions, so the baseline does not change every day.
  { name: 'attendance-day', family: 'list', pinClock: true, path: (ids) => (ids.sessionDate ? `/attendance?date=${ids.sessionDate}` : '/attendance') },

  // Form family
  { name: 'enrollment', family: 'form', master: true, path: '/enrollment' },

  // Detail family (master: LessonDetailPage)
  { name: 'lesson-detail', family: 'detail', master: true, path: (ids) => (ids.lesson ? `/lessons/${ids.lesson}` : null) },
  { name: 'hall-calendar', family: 'detail', path: (ids) => (ids.hall ? `/lessons/halls/${ids.hall}` : null) },
  { name: 'student-profile', family: 'detail', master: true, path: (ids) => (ids.student ? `/students/${ids.student}` : null) },
  { name: 'exam-detail', family: 'detail', path: (ids) => (ids.exam ? `/exams/${ids.exam}` : null) },
  { name: 'attendance-sheet', family: 'detail', master: true, path: (ids) => (ids.session ? `/attendance/${ids.session}` : null) },
  { name: 'evaluation-sheet', family: 'detail', path: (ids) => (ids.session ? `/evaluation/${ids.session}` : null) },
  { name: 'lottery-detail', family: 'detail', path: (ids) => (ids.lottery ? `/lottery/${ids.lottery}` : null) },
  { name: 'competition-detail', family: 'detail', path: (ids) => (ids.competition ? `/competitions/${ids.competition}` : null) },
]
