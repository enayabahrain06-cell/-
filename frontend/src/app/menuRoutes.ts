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
  // Phase 5, الحضور
  division_attendance: { path: '/division-attendance', permissions: ['attendance.record'] },
  division_attendance_monitor: { path: '/division-attendance-monitor', permissions: ['attendance.view'] },
  attendance_monitor: { path: '/attendance-monitor', permissions: ['lessons.manage'] },
  supervisor_attendance: { path: '/supervisor-attendance', query: { tab: 'record' }, isDefault: true, permissions: ['staff_attendance.record'] },
  view_supervisor_attendance: { path: '/supervisor-attendance', query: { tab: 'view' }, permissions: ['staff_attendance.view', 'staff_attendance.record'] },
  view_teacher_attendance: { path: '/teacher-attendance', permissions: ['staff_attendance.view', 'staff_attendance.record'] },
  // Phase 6, الدرجات
  grade_distribution: { path: '/grade-distribution', permissions: ['grades.manage'] },
  required_lessons: { path: '/required-lessons', permissions: ['grades.manage', 'exams.manage'] },
  upload_exam_grades: { path: '/upload-exam-grades', permissions: ['exams.grade'] },
  grades: { path: '/grades', query: { tab: 'record' }, isDefault: true, permissions: ['grades.record'] },
  view_grades: { path: '/grades', query: { tab: 'view' }, permissions: ['grades.view'] },
  download_grades: { path: '/grades', query: { tab: 'download' }, permissions: ['grades.view'] },
  grade_submission_monitor: { path: '/grade-submission-monitor', permissions: ['grades.manage'] },
  top_students: { path: '/honor', query: { tab: 'grades' }, permissions: ['grades.view'] },
  // Phase 7, البرامج والرحلات (one activities engine; each entry opens its tab)
  programs: { path: '/programs', query: { tab: 'list' }, isDefault: true, permissions: ['activities.view', 'activities.manage'] },
  program_registration: { path: '/programs', query: { tab: 'register' }, permissions: ['activities.register'] },
  program_students: { path: '/programs', query: { tab: 'students' }, permissions: ['activities.view'] },
  program_fee_payment: { path: '/programs', query: { tab: 'fee_payment' }, permissions: ['payments.record'] },
  program_fee_followup: { path: '/programs', query: { tab: 'fee_followup' }, permissions: ['wallets.view'] },
  program_book_delivery: { path: '/programs', query: { tab: 'book_delivery' }, permissions: ['activities.manage'] },
  program_book_followup: { path: '/programs', query: { tab: 'book_followup' }, permissions: ['activities.view'] },
  program_attendance: { path: '/programs', query: { tab: 'attendance' }, permissions: ['activities.attendance'] },
  program_attendance_followup: { path: '/programs', query: { tab: 'attendance_followup' }, permissions: ['activities.view'] },
  program_evaluation: { path: '/programs', query: { tab: 'evaluation' }, permissions: ['activities.evaluate'] },
  view_program_evaluation: { path: '/programs', query: { tab: 'evaluation_view' }, permissions: ['activities.view'] },
  trips: { path: '/trips', query: { tab: 'list' }, isDefault: true, permissions: ['activities.view', 'activities.manage'] },
  trip_registration: { path: '/trips', query: { tab: 'register' }, permissions: ['activities.register'] },
  trip_attendance: { path: '/trips', query: { tab: 'attendance' }, permissions: ['activities.attendance'] },
  trip_attendance_followup: { path: '/trips', query: { tab: 'attendance_followup' }, permissions: ['activities.view'] },
  trip_fee_payment: { path: '/trips', query: { tab: 'fee_payment' }, permissions: ['payments.record'] },
  trip_fee_followup: { path: '/trips', query: { tab: 'fee_followup' }, permissions: ['wallets.view'] },
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
  // Phase 5, الحضور
  { key: 'division_attendance', path: '/division-attendance', icon: 'attendance', permissions: ['attendance.record'] },
  { key: 'division_attendance_monitor', path: '/division-attendance-monitor', icon: 'alert', permissions: ['attendance.view'] },
  { key: 'attendance_monitor', path: '/attendance-monitor', icon: 'alert', permissions: ['lessons.manage'] },
  { key: 'supervisor_attendance', path: '/supervisor-attendance', icon: 'attendance', permissions: ['staff_attendance.view', 'staff_attendance.record'] },
  { key: 'teacher_attendance', path: '/teacher-attendance', icon: 'eye', permissions: ['staff_attendance.view', 'staff_attendance.record'] },
  // Phase 6, الدرجات
  { key: 'grade_distribution', path: '/grade-distribution', icon: 'chart', permissions: ['grades.manage'] },
  { key: 'required_lessons', path: '/required-lessons', icon: 'lessons', permissions: ['grades.manage', 'exams.manage'] },
  { key: 'upload_exam_grades', path: '/upload-exam-grades', icon: 'download', permissions: ['exams.grade'] },
  { key: 'gradebook', path: '/grades', icon: 'exams', permissions: ['grades.view', 'grades.record'] },
  { key: 'grade_monitor', path: '/grade-submission-monitor', icon: 'alert', permissions: ['grades.manage'] },
  // Phase 7, البرامج والرحلات
  { key: 'activities_programs', path: '/programs', icon: 'trophy', permissions: ['activities.view', 'activities.manage', 'activities.register', 'activities.attendance', 'activities.evaluate', 'payments.record', 'wallets.view'] },
  { key: 'activities_trips', path: '/trips', icon: 'pin', permissions: ['activities.view', 'activities.manage', 'activities.register', 'activities.attendance', 'activities.evaluate', 'payments.record', 'wallets.view'] },
  // Phase 8, معرض الصور
  { key: 'gallery', path: '/gallery', icon: 'camera', permissions: ['gallery.view'] },
]

/** Every staff route: the core ones in nav.ts plus EXTRA_ROUTES. */
export const ALL_ROUTES: NavSection[] = [...NAV_SECTIONS, ...EXTRA_ROUTES]

/** A menu entry with its build switched on when it appears in BUILT_ENTRIES. */
export function withBuilt(e: MenuEntry): MenuEntry {
  const b = BUILT_ENTRIES[e.key]
  return b ? { ...e, ...b } : e
}
