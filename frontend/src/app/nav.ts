/**
 * Routes of the staff shell (one per screen) — used by the router and the mobile page headers.
 * The sidebar and the mobile المزيد sheet are built from MENU below, not from this list.
 */
export interface NavSection {
  key: string
  path: string
  icon: string
  permissions: string[]
}

export const NAV_SECTIONS: NavSection[] = [
  { key: 'dashboard', path: '/', icon: 'dashboard', permissions: ['dashboard.view'] },
  { key: 'students', path: '/students', icon: 'students', permissions: ['students.view'] },
  { key: 'student_lookup', path: '/students/find', icon: 'search', permissions: ['students.view'] },
  { key: 'enrollment', path: '/enrollment', icon: 'enroll', permissions: ['enrollment.quick'] },
  { key: 'teachers', path: '/teachers', icon: 'teachers', permissions: ['teachers.view'] },
  { key: 'lessons', path: '/lessons', icon: 'lessons', permissions: ['lessons.view', 'locations.view'] },
  { key: 'term_setup', path: '/term-setup', icon: 'clock', permissions: ['term_setup.view', 'term_setup.manage'] },
  { key: 'attendance', path: '/attendance', icon: 'attendance', permissions: ['attendance.view', 'attendance.record'] },
  { key: 'evaluation', path: '/evaluation', icon: 'evaluation', permissions: ['evaluations.view', 'evaluations.record'] },
  { key: 'exams', path: '/exams', icon: 'exams', permissions: ['exams.view'] },
  { key: 'certificates', path: '/certificates', icon: 'certificate', permissions: ['certificates.view'] },
  { key: 'honor', path: '/honor', icon: 'medal', permissions: ['honor.view'] },
  { key: 'competitions', path: '/competitions', icon: 'trophy', permissions: ['competitions.view', 'challenges.view'] },
  { key: 'lottery', path: '/lottery', icon: 'lottery', permissions: ['lottery.view'] },
  { key: 'packages', path: '/packages', icon: 'packages', permissions: ['packages.view', 'registrations.view'] },
  { key: 'payments', path: '/payments', icon: 'payments', permissions: ['wallets.view', 'payments.record'] },
  { key: 'messages', path: '/messages', icon: 'messages', permissions: ['messages.view', 'messages.send'] },
  { key: 'reports', path: '/reports', icon: 'reports', permissions: ['reports.view'] },
  { key: 'users', path: '/users', icon: 'users', permissions: ['users.view', 'roles.manage'] },
  { key: 'master_data', path: '/master-data', icon: 'table', permissions: ['terms.manage', 'nights.manage', 'levels.manage', 'subjects.manage', 'users.view', 'term_setup.manage'] },
  { key: 'menu', path: '/menu', icon: 'menu', permissions: ['menu.manage'] },
  { key: 'settings', path: '/settings', icon: 'settings', permissions: ['settings.manage'] },
  { key: 'audit', path: '/audit', icon: 'eye', permissions: ['audit.view'] },
]

/**
 * One menu entry. The label is nav:menu.<key> — the exact name from the other system for its 79 features.
 * `path` null: not built yet (hidden until its phase ships). `query`: the tab it opens; `isDefault`: it is also
 * active when that query key is absent (the page's first tab). `prefixes`: extra paths that belong to it.
 */
export interface MenuEntry {
  key: string
  path: string | null
  query?: Record<string, string>
  isDefault?: boolean
  prefixes?: string[]
  icon: string
  permissions: string[]
}

export interface MenuSectionDef {
  key: string
  icon: string
  entries: MenuEntry[]
}

const soon = (key: string, icon: string): MenuEntry => ({ key, path: null, icon, permissions: [] })

/** لوحة التحكم: always at the very top, on its own. */
export const MENU_TOP: MenuEntry = { key: 'dashboard', path: '/', icon: 'dashboard', permissions: ['dashboard.view'] }

/** The 10 sections, in the other system's order, then التواصل والتقارير; our own extras sit inside them. */
export const MENU: MenuSectionDef[] = [
  {
    key: 'system', icon: 'settings', entries: [
      { key: 'menu', path: '/menu', icon: 'menu', permissions: ['menu.manage'] },
      { key: 'permissions', path: '/users', query: { tab: 'roles' }, icon: 'users', permissions: ['roles.manage'] },
      { key: 'committee_info', path: '/settings', query: { group: 'authority' }, icon: 'home', permissions: ['settings.manage'] },
      { key: 'users', path: '/users', query: { tab: 'users' }, isDefault: true, icon: 'users', permissions: ['users.view'] },
      { key: 'settings', path: '/settings', icon: 'settings', permissions: ['settings.manage'] },
      { key: 'audit', path: '/audit', icon: 'eye', permissions: ['audit.view'] },
    ],
  },
  {
    key: 'lists', icon: 'table', entries: [
      { key: 'terms', path: '/master-data', query: { tab: 'terms' }, isDefault: true, icon: 'history', permissions: ['terms.manage'] },
      { key: 'nights', path: '/master-data', query: { tab: 'nights' }, icon: 'clock', permissions: ['nights.manage'] },
      { key: 'rooms', path: '/lessons', query: { tab: 'halls' }, prefixes: ['/lessons/halls'], icon: 'pin', permissions: ['locations.view'] },
      { key: 'subjects', path: '/master-data', query: { tab: 'subjects' }, icon: 'evaluation', permissions: ['subjects.manage'] },
      { key: 'classes', path: '/lessons', query: { tab: 'circles' }, isDefault: true, icon: 'lessons', permissions: ['lessons.view'] },
      { key: 'levels', path: '/master-data', query: { tab: 'levels' }, icon: 'chart', permissions: ['levels.manage'] },
      { key: 'teachers', path: '/teachers', icon: 'teachers', permissions: ['teachers.view'] },
      { key: 'supervisors', path: '/master-data', query: { tab: 'supervisors' }, icon: 'eye', permissions: ['users.view', 'term_setup.manage'] },
    ],
  },
  {
    key: 'term_setup', icon: 'clock', entries: [
      { key: 'level_rooms', path: '/term-setup', query: { tab: 'level_rooms' }, isDefault: true, icon: 'pin', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'level_subjects', path: '/term-setup', query: { tab: 'level_subjects' }, icon: 'evaluation', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'subject_lessons', path: '/term-setup', query: { tab: 'subject_lessons' }, icon: 'lessons', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'plan', path: '/term-setup', query: { tab: 'plan' }, icon: 'edit', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'plan_view', path: '/term-setup', query: { tab: 'plan_view' }, icon: 'table', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'night_supervisors', path: '/term-setup', query: { tab: 'supervisors' }, icon: 'eye', permissions: ['term_setup.view', 'term_setup.manage'] },
      { key: 'timetable', path: '/term-setup', query: { tab: 'timetable' }, icon: 'attendance', permissions: ['term_setup.view', 'term_setup.manage'] },
    ],
  },
  {
    key: 'registration', icon: 'enroll', entries: [
      { key: 'registration', path: '/enrollment', icon: 'enroll', permissions: ['enrollment.quick'] },
      { key: 'students', path: '/students', icon: 'students', permissions: ['students.view'] },
      soon('level_distribution', 'students'),
      soon('promote_students', 'chevron'),
      soon('update_level', 'refresh'),
      soon('upload_archive', 'download'),
      soon('view_archive', 'history'),
      { key: 'payment', path: '/payments', icon: 'payments', permissions: ['wallets.view', 'payments.record'] },
      soon('payment_followup', 'wallet'),
      soon('books', 'lessons'),
      soon('books_followup', 'check'),
      { key: 'packages', path: '/packages', icon: 'packages', permissions: ['packages.view', 'registrations.view'] },
      { key: 'lottery', path: '/lottery', icon: 'lottery', permissions: ['lottery.view'] },
    ],
  },
  {
    key: 'followup', icon: 'evaluation', entries: [
      { key: 'student_details', path: '/students/find', isDefault: true, query: { view: 'details' }, icon: 'students', permissions: ['students.view'] },
      { key: 'student_info', path: '/students/find', query: { view: 'info' }, icon: 'edit', permissions: ['students.view'] },
      soon('student_notes', 'edit'),
      soon('general_notes', 'edit'),
      soon('level_notes', 'edit'),
      soon('view_level_notes', 'eye'),
      soon('level_subject_notes', 'edit'),
      soon('view_level_subject_notes', 'eye'),
      soon('divisions', 'students'),
      soon('evaluation_criteria', 'evaluation'),
      { key: 'student_evaluation', path: '/evaluation', icon: 'evaluation', permissions: ['evaluations.view', 'evaluations.record'] },
      { key: 'view_student_evaluation', path: '/reports', query: { report: 'evaluation' }, icon: 'eye', permissions: ['reports.view'] },
      soon('division_evaluation', 'evaluation'),
      soon('view_division_evaluation', 'eye'),
      { key: 'quran_lessons', path: '/reports', query: { report: 'juz' }, icon: 'lessons', permissions: ['reports.view'] },
      soon('update_subject_lessons', 'refresh'),
    ],
  },
  {
    key: 'attendance', icon: 'attendance', entries: [
      { key: 'student_attendance', path: '/attendance', icon: 'attendance', permissions: ['attendance.view', 'attendance.record'] },
      { key: 'view_student_attendance', path: '/reports', query: { report: 'attendance' }, icon: 'eye', permissions: ['reports.view'] },
      soon('division_attendance', 'attendance'),
      soon('supervisor_attendance', 'attendance'),
      soon('view_supervisor_attendance', 'eye'),
      soon('view_teacher_attendance', 'eye'),
      soon('attendance_monitor', 'alert'),
      soon('division_attendance_monitor', 'alert'),
    ],
  },
  {
    key: 'programs', icon: 'trophy', entries: [
      soon('programs', 'trophy'),
      soon('program_registration', 'enroll'),
      soon('program_students', 'students'),
      soon('program_fee_payment', 'payments'),
      soon('program_fee_followup', 'wallet'),
      soon('program_book_delivery', 'lessons'),
      soon('program_book_followup', 'check'),
      soon('program_attendance', 'attendance'),
      soon('program_attendance_followup', 'eye'),
      soon('program_evaluation', 'evaluation'),
      soon('view_program_evaluation', 'eye'),
      { key: 'competitions', path: '/competitions', icon: 'trophy', permissions: ['competitions.view', 'challenges.view'] },
    ],
  },
  {
    key: 'trips', icon: 'pin', entries: [
      soon('trips', 'pin'),
      soon('trip_registration', 'enroll'),
      soon('trip_attendance', 'attendance'),
      soon('trip_attendance_followup', 'eye'),
      soon('trip_fee_payment', 'payments'),
      soon('trip_fee_followup', 'wallet'),
    ],
  },
  {
    key: 'grades', icon: 'exams', entries: [
      soon('grade_distribution', 'chart'),
      { key: 'exams', path: '/exams', icon: 'exams', permissions: ['exams.view'] },
      soon('required_lessons', 'lessons'),
      soon('upload_exam_grades', 'download'),
      soon('grades', 'exams'),
      soon('view_grades', 'eye'),
      soon('download_grades', 'download'),
      soon('grade_submission_monitor', 'alert'),
      soon('top_students', 'medal'),
      { key: 'honor', path: '/honor', icon: 'medal', permissions: ['honor.view'] },
      { key: 'certificates', path: '/certificates', icon: 'certificate', permissions: ['certificates.view'] },
    ],
  },
  {
    key: 'communication', icon: 'messages', entries: [
      { key: 'messages', path: '/messages', icon: 'messages', permissions: ['messages.view', 'messages.send'] },
      { key: 'reports', path: '/reports', icon: 'reports', isDefault: true, query: { report: '' }, permissions: ['reports.view'] },
    ],
  },
]

/** The link of an entry (path plus its query). */
export function entryHref(e: MenuEntry): string {
  const q = new URLSearchParams(Object.entries(e.query ?? {}).filter(([, v]) => v !== ''))
  const s = q.toString()
  return `${e.path}${s ? `?${s}` : ''}`
}

/**
 * How well an entry matches the current location (0 = not at all). Its own path and query win; then its path
 * with the query key absent when it is the page's default; then a detail page under it.
 */
export function entryScore(e: MenuEntry, pathname: string, params: URLSearchParams): number {
  if (!e.path) return 0
  const onPath = pathname === e.path
  const under = e.path !== '/' && (pathname.startsWith(`${e.path}/`) || (e.prefixes ?? []).some((p) => pathname === p || pathname.startsWith(`${p}/`)))
  if (!onPath && !under) return 0
  const query = Object.entries(e.query ?? {})
  if (onPath && query.length > 0) {
    if (query.every(([k, v]) => (params.get(k) ?? '') === v)) return 4
    if (e.isDefault && query.every(([k]) => !params.get(k))) return 3
    return 0
  }
  if (onPath) return 3
  return (e.prefixes ?? []).some((p) => pathname.startsWith(p)) ? 2 : 1
}
