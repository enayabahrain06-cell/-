/**
 * The staff sections of the sidebar, in display order. A section shows when the user holds any of its permissions.
 * Grouped by workflow: people, teaching, engagement, finance, communication, administration.
 */
export type NavGroup = 'people' | 'teaching' | 'engagement' | 'finance' | 'communication' | 'admin'

export interface NavSection {
  key: string
  path: string
  icon: string
  permissions: string[]
  /** Sidebar group heading key (nav:groups.*). Items without one sit above the first heading. */
  group?: NavGroup
}

export const NAV_SECTIONS: NavSection[] = [
  { key: 'dashboard', path: '/', icon: 'dashboard', permissions: ['dashboard.view'] },
  { key: 'students', path: '/students', icon: 'students', permissions: ['students.view'], group: 'people' },
  { key: 'enrollment', path: '/enrollment', icon: 'enroll', permissions: ['enrollment.quick'], group: 'people' },
  { key: 'teachers', path: '/teachers', icon: 'teachers', permissions: ['teachers.view'], group: 'people' },
  { key: 'lessons', path: '/lessons', icon: 'lessons', permissions: ['lessons.view', 'locations.view'], group: 'teaching' },
  { key: 'term_setup', path: '/term-setup', icon: 'clock', permissions: ['term_setup.view', 'term_setup.manage'], group: 'teaching' },
  { key: 'attendance', path: '/attendance', icon: 'attendance', permissions: ['attendance.view', 'attendance.record'], group: 'teaching' },
  { key: 'evaluation', path: '/evaluation', icon: 'evaluation', permissions: ['evaluations.view', 'evaluations.record'], group: 'teaching' },
  { key: 'exams', path: '/exams', icon: 'exams', permissions: ['exams.view'], group: 'teaching' },
  { key: 'certificates', path: '/certificates', icon: 'certificate', permissions: ['certificates.view'], group: 'teaching' },
  { key: 'honor', path: '/honor', icon: 'medal', permissions: ['honor.view'], group: 'engagement' },
  { key: 'competitions', path: '/competitions', icon: 'trophy', permissions: ['competitions.view', 'challenges.view'], group: 'engagement' },
  { key: 'lottery', path: '/lottery', icon: 'lottery', permissions: ['lottery.view'], group: 'engagement' },
  { key: 'packages', path: '/packages', icon: 'packages', permissions: ['packages.view', 'registrations.view'], group: 'finance' },
  { key: 'payments', path: '/payments', icon: 'payments', permissions: ['wallets.view', 'payments.record'], group: 'finance' },
  { key: 'messages', path: '/messages', icon: 'messages', permissions: ['messages.view', 'messages.send'], group: 'communication' },
  { key: 'reports', path: '/reports', icon: 'reports', permissions: ['reports.view'], group: 'communication' },
  { key: 'users', path: '/users', icon: 'users', permissions: ['users.view', 'roles.manage'], group: 'admin' },
  { key: 'master_data', path: '/master-data', icon: 'table', permissions: ['terms.manage', 'levels.manage', 'subjects.manage'], group: 'admin' },
  { key: 'settings', path: '/settings', icon: 'settings', permissions: ['settings.manage'], group: 'admin' },
  { key: 'audit', path: '/audit', icon: 'eye', permissions: ['audit.view'], group: 'admin' },
]
