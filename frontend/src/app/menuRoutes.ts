import { NAV_SECTIONS, type MenuEntry, type NavSection } from './nav'

/**
 * Screens built after the naming step. nav.ts lists every menu entry (unbuilt ones as `soon()`); an entry listed
 * here is switched on with its link and permissions, and its route is registered. Keeping them here means nav.ts
 * (the menu's order and names) is not edited for every new screen.
 */
export const BUILT_ENTRIES: Record<string, Pick<MenuEntry, 'path' | 'permissions'> & Partial<Pick<MenuEntry, 'query' | 'isDefault' | 'prefixes'>>> = {
  // Phase 3, التسجيل
  payment_followup: { path: '/payment-followup', permissions: ['wallets.view'] },
  books: { path: '/books', query: { tab: 'books' }, isDefault: true, permissions: ['books.view', 'books.manage'] },
  books_followup: { path: '/books', query: { tab: 'followup' }, permissions: ['books.view', 'books.manage'] },
  level_distribution: { path: '/level-distribution', permissions: ['distribution.manage'] },
  promote_students: { path: '/promote-students', permissions: ['distribution.manage'] },
  update_level: { path: '/update-level', permissions: ['distribution.manage'] },
  upload_archive: { path: '/archive', query: { tab: 'upload' }, permissions: ['archive.manage'] },
  view_archive: { path: '/archive', query: { tab: 'view' }, isDefault: true, permissions: ['archive.view', 'archive.manage'] },
  // Phase 4, متابعة التعليم
  student_notes: { path: '/notes', query: { tab: 'students' }, isDefault: true, permissions: ['notes.view', 'notes.manage'] },
  general_notes: { path: '/notes', query: { tab: 'general' }, permissions: ['notes.view', 'notes.manage'] },
  level_notes: { path: '/notes', query: { tab: 'levels' }, permissions: ['notes.view', 'notes.manage'] },
  view_level_notes: { path: '/notes', query: { tab: 'levels_view' }, permissions: ['notes.view', 'notes.manage'] },
  level_subject_notes: { path: '/notes', query: { tab: 'subjects' }, permissions: ['notes.view', 'notes.manage'] },
  view_level_subject_notes: { path: '/notes', query: { tab: 'subjects_view' }, permissions: ['notes.view', 'notes.manage'] },
  divisions: { path: '/divisions', permissions: ['lessons.view', 'divisions.manage'] },
  evaluation_criteria: { path: '/evaluation-criteria', permissions: ['evaluation_criteria.manage'] },
  division_evaluation: { path: '/division-evaluation', query: { tab: 'record' }, isDefault: true, permissions: ['evaluations.record'] },
  view_division_evaluation: { path: '/division-evaluation', query: { tab: 'view' }, permissions: ['evaluations.view'] },
  quran_lessons: { path: '/quran-lessons', query: {}, permissions: ['evaluations.view'] },
  update_subject_lessons: { path: '/subject-progress', permissions: ['subject_progress.record'] },
}

/** Routes of those screens (path → page is mapped in routes.tsx by key). */
export const EXTRA_ROUTES: NavSection[] = [
  // Phase 3, التسجيل
  { key: 'payment_followup', path: '/payment-followup', icon: 'wallet', permissions: ['wallets.view'] },
  { key: 'books', path: '/books', icon: 'lessons', permissions: ['books.view', 'books.manage'] },
  { key: 'level_distribution', path: '/level-distribution', icon: 'students', permissions: ['distribution.manage'] },
  { key: 'promote_students', path: '/promote-students', icon: 'chevron', permissions: ['distribution.manage'] },
  { key: 'update_level', path: '/update-level', icon: 'refresh', permissions: ['distribution.manage'] },
  { key: 'archive', path: '/archive', icon: 'history', permissions: ['archive.view', 'archive.manage'] },
  // Phase 4, متابعة التعليم
  { key: 'notes', path: '/notes', icon: 'edit', permissions: ['notes.view', 'notes.manage'] },
  { key: 'divisions', path: '/divisions', icon: 'students', permissions: ['lessons.view', 'divisions.manage'] },
  { key: 'evaluation_criteria', path: '/evaluation-criteria', icon: 'evaluation', permissions: ['evaluation_criteria.manage'] },
  { key: 'division_evaluation', path: '/division-evaluation', icon: 'evaluation', permissions: ['evaluations.record', 'evaluations.view'] },
  { key: 'quran_lessons', path: '/quran-lessons', icon: 'lessons', permissions: ['evaluations.view'] },
  { key: 'subject_progress', path: '/subject-progress', icon: 'refresh', permissions: ['subject_progress.record'] },
]

/** Every staff route: the core ones in nav.ts plus EXTRA_ROUTES. */
export const ALL_ROUTES: NavSection[] = [...NAV_SECTIONS, ...EXTRA_ROUTES]

/** A menu entry with its build switched on when it appears in BUILT_ENTRIES. */
export function withBuilt(e: MenuEntry): MenuEntry {
  const b = BUILT_ENTRIES[e.key]
  return b ? { ...e, ...b } : e
}
