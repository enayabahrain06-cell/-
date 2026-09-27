/** The 13 staff sections of the sidebar. A section shows when the user holds any of its permissions. */
export interface NavSection {
  key: string
  path: string
  icon: string
  permissions: string[]
}

export const NAV_SECTIONS: NavSection[] = [
  { key: 'dashboard', path: '/', icon: 'dashboard', permissions: ['dashboard.view'] },
  { key: 'lessons', path: '/lessons', icon: 'lessons', permissions: ['lessons.view', 'locations.view'] },
  { key: 'students', path: '/students', icon: 'students', permissions: ['students.view'] },
  { key: 'teachers', path: '/teachers', icon: 'teachers', permissions: ['teachers.view'] },
  { key: 'attendance', path: '/attendance', icon: 'attendance', permissions: ['attendance.view', 'attendance.record'] },
  { key: 'evaluation', path: '/evaluation', icon: 'evaluation', permissions: ['evaluations.view', 'evaluations.record'] },
  { key: 'exams', path: '/exams', icon: 'exams', permissions: ['exams.view'] },
  { key: 'lottery', path: '/lottery', icon: 'lottery', permissions: ['lottery.view'] },
  { key: 'packages', path: '/packages', icon: 'packages', permissions: ['packages.view', 'registrations.view'] },
  { key: 'payments', path: '/payments', icon: 'payments', permissions: ['wallets.view', 'payments.record'] },
  { key: 'messages', path: '/messages', icon: 'messages', permissions: ['messages.view', 'messages.send'] },
  { key: 'reports', path: '/reports', icon: 'reports', permissions: ['reports.view'] },
  { key: 'users', path: '/users', icon: 'users', permissions: ['users.view', 'roles.manage'] },
  { key: 'settings', path: '/settings', icon: 'settings', permissions: ['settings.manage'] },
]
