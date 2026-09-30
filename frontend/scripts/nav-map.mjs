#!/usr/bin/env node
/**
 * nav_v2 map generator (docs/07-NAV-V2.md). The approved table lives in SPEC below; this script writes:
 *   src/app/nav-map.json            the map the app reads (sidebar, tabs, modes, redirects, Ctrl+K, القائمة)
 *   src/locales/{ar,en}/nav.json    the "v2" block (tab, mode and kind labels, names of the extra screens)
 *   ../docs/07-NAV-V2.md            the table with the count check and the list of moves
 *   docker/nav-v2-redirects.conf    nginx 301s for every old path (include it once nav_v2 is permanent)
 * and fails when a menu feature is missing, appears twice, or has no name.
 *
 *   node scripts/nav-map.mjs          regenerate
 *   node scripts/nav-map.mjs --check  fail if a generated file is out of date (CI)
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const check = process.argv.includes('--check')

/** A screen shown by a tab: `mode` (and optional `kind`) value, the old menu feature it carries, who may open it. */
const v = (mode, feature, perms, page, path, query = {}, more = {}) => ({ mode, feature, perms, page, path, query, ...more })
/** A screen that had no menu entry of its own (an in-page tab of the old page): named by `extra`. */
const x = (mode, extra, perms, page, path, query = {}, more = {}) => ({ mode, extra, perms, page, path, query, ...more })

const REC = ['تسجيل', 'Record'], VIEW = ['عرض', 'View'], MON = ['مراقبة', 'Monitor'], TRACK = ['المتابعة', 'Follow-up']
const TS = ['term_setup.view', 'term_setup.manage']
const NOTES = ['notes.view', 'notes.manage']
const ACT = 'activities.view'

/** Extra screens (not among the 89 menu features): their full names for the document title and Ctrl+K. */
const EXTRA = {
  room_bookings: ['حجوزات الغرف', 'Room bookings'],
  registration_requests: ['طلبات التسجيل', 'Registration requests'],
  invoices: ['الفواتير', 'Invoices'],
  refunds: ['الاستردادات', 'Refunds'],
  finance_report: ['التقرير المالي', 'Finance report'],
  monthly_evaluation: ['التقييم الشهري', 'Monthly evaluation'],
  record_teacher_attendance: ['تسجيل حضور المعلمين', 'Record teacher attendance'],
  challenges: ['التحديات', 'Challenges'],
  badges: ['الأوسمة', 'Badges'],
  message_inbox: ['الوارد والأعذار', 'Inbox & excuses'],
  message_send: ['إرسال رسالة', 'Send a message'],
  message_templates: ['قوالب الرسائل', 'Message templates'],
  message_rules: ['قواعد الرسائل', 'Messaging rules'],
  whatsapp: ['واتساب', 'WhatsApp'],
}

/**
 * The approved table. Section order is P6 (with التحفيز after الدرجات). Inside a tab, the first screen the user may
 * open is the default. `from`: the old section when a feature moved (P5).
 */
const SPEC = [
  { key: 'system', path: '/system', icon: 'settings', tabs: [
    { key: 'users', label: ['المستخدمون', 'Users'], modes: { users: ['الحسابات', 'Accounts'], roles: ['الصلاحيات', 'Permissions'] }, views: [
      v('users', 'users', ['users.view'], 'users', '/users', { tab: 'users' }, { isDefault: true }),
      v('roles', 'permissions', ['users.view', 'roles.manage'], 'users', '/users', { tab: 'roles' }),
    ] },
    { key: 'general', label: ['عامة', 'General'], modes: { general: ['الإعدادات', 'Settings'], committee: ['اللجنة', 'Committee'] }, views: [
      v('general', 'settings', ['settings.manage'], 'settings', '/settings', {}, { isDefault: true }),
      v('committee', 'committee_info', ['settings.manage'], 'settings', '/settings', { group: 'authority' }),
    ] },
    { key: 'menu', label: ['القائمة', 'Menu'], views: [v('main', 'menu', ['menu.manage'], 'menu', '/menu')] },
    { key: 'audit', label: ['سجل التدقيق', 'Audit log'], views: [v('main', 'audit', ['audit.view'], 'audit', '/audit')] },
  ] },
  { key: 'lists', path: '/lists', icon: 'table', tabs: [
    { key: 'terms', label: ['الفصول الدراسية', 'Terms'], views: [v('main', 'terms', ['terms.manage'], 'master_data', '/master-data', { tab: 'terms' }, { isDefault: true })] },
    { key: 'nights', label: ['الليالي', 'Nights'], views: [v('main', 'nights', ['nights.manage'], 'master_data', '/master-data', { tab: 'nights' })] },
    { key: 'rooms', label: ['الغرف', 'Rooms'], modes: { rooms: ['الغرف', 'Rooms'], bookings: ['الحجوزات', 'Bookings'] }, views: [
      v('rooms', 'rooms', ['locations.view'], 'lessons', '/lessons', { tab: 'halls' }, { prefixes: ['/lessons/halls'] }),
      x('bookings', 'room_bookings', ['locations.view'], 'lessons', '/lessons', { tab: 'bookings' }),
    ] },
    { key: 'subjects', label: ['المواد', 'Subjects'], views: [v('main', 'subjects', ['subjects.manage'], 'master_data', '/master-data', { tab: 'subjects' })] },
    { key: 'classes', label: ['الصفوف', 'Classes'], views: [v('main', 'classes', ['lessons.view'], 'lessons', '/lessons', { tab: 'circles' }, { isDefault: true })] },
    { key: 'levels', label: ['المستويات', 'Levels'], views: [v('main', 'levels', ['levels.manage'], 'master_data', '/master-data', { tab: 'levels' })] },
    { key: 'teachers', label: ['المعلمون', 'Teachers'], views: [v('main', 'teachers', ['teachers.view'], 'teachers', '/teachers')] },
    { key: 'supervisors', label: ['المشرفون', 'Supervisors'], views: [v('main', 'supervisors', ['users.view', 'term_setup.manage'], 'master_data', '/master-data', { tab: 'supervisors' })] },
  ] },
  { key: 'term_setup', path: '/term-setup', icon: 'clock', tabs: [
    { key: 'level_rooms', label: ['غرف المستويات', 'Level rooms'], views: [v('main', 'level_rooms', TS, 'term_setup', '/term-setup', { tab: 'level_rooms' }, { isDefault: true })] },
    { key: 'subjects', label: ['المواد والدروس', 'Subjects & lessons'], modes: { subjects: ['المواد', 'Subjects'], lessons: ['الدروس', 'Lessons'] }, views: [
      v('subjects', 'level_subjects', TS, 'term_setup', '/term-setup', { tab: 'level_subjects' }),
      v('lessons', 'subject_lessons', TS, 'term_setup', '/term-setup', { tab: 'subject_lessons' }),
    ] },
    { key: 'plan', label: ['الخطة', 'Plan'], modes: { edit: ['تعديل', 'Edit'], view: VIEW }, views: [
      v('edit', 'plan', TS, 'term_setup', '/term-setup', { tab: 'plan' }),
      v('view', 'plan_view', TS, 'term_setup', '/term-setup', { tab: 'plan_view' }),
    ] },
    { key: 'supervisors', label: ['مشرفو الليالي', 'Night supervisors'], views: [v('main', 'night_supervisors', TS, 'term_setup', '/term-setup', { tab: 'supervisors' })] },
    { key: 'timetable', label: ['الجدول الدراسي', 'Timetable'], views: [v('main', 'timetable', TS, 'term_setup', '/term-setup', { tab: 'timetable' })] },
  ] },
  { key: 'registration', path: '/registration', icon: 'enroll', tabs: [
    { key: 'new', label: ['طالب جديد', 'New student'], views: [v('main', 'registration', ['enrollment.quick'], 'enrollment', '/enrollment')] },
    { key: 'students', label: ['الطلبة', 'Students'], views: [v('main', 'students', ['students.view'], 'students', '/students')] },
    { key: 'levels', label: ['المستويات', 'Levels'], modes: { distribute: ['التوزيع', 'Distribute'], promote: ['الترفيع', 'Promote'], update: ['التحديث', 'Update'] }, views: [
      v('distribute', 'level_distribution', ['distribution.manage'], 'level_distribution', '/level-distribution'),
      v('promote', 'promote_students', ['distribution.manage'], 'promote_students', '/promote-students'),
      v('update', 'update_level', ['distribution.manage'], 'update_level', '/update-level'),
    ] },
    { key: 'archive', label: ['الأرشيف', 'Archive'], modes: { view: VIEW, upload: ['رفع', 'Upload'] }, views: [
      v('view', 'view_archive', ['archive.view', 'archive.manage'], 'archive', '/archive', { tab: 'view' }, { isDefault: true }),
      v('upload', 'upload_archive', ['archive.manage'], 'archive', '/archive', { tab: 'upload' }),
    ] },
    { key: 'fees', label: ['الرسوم الدراسية', 'Tuition fees'], modes: { pay: ['الدفع', 'Pay'], track: TRACK, invoices: ['الفواتير', 'Invoices'], refunds: ['الاستردادات', 'Refunds'], report: ['التقرير', 'Report'] }, views: [
      v('pay', 'payment', ['wallets.view', 'payments.record'], 'payments', '/payments', { tab: 'payments' }, { isDefault: true }),
      v('track', 'payment_followup', ['wallets.view'], 'payment_followup', '/payment-followup'),
      x('invoices', 'invoices', ['wallets.view', 'payments.record'], 'payments', '/payments', { tab: 'invoices' }),
      x('refunds', 'refunds', ['wallets.view', 'payments.record'], 'payments', '/payments', { tab: 'refunds' }),
      x('report', 'finance_report', ['reports.view', 'wallets.view'], 'payments', '/payments', { tab: 'report' }),
    ] },
    { key: 'books', label: ['الكتب الدراسية', 'Textbooks'], modes: { books: ['الكتب', 'Books'], track: TRACK }, views: [
      v('books', 'books', ['books.view', 'books.manage'], 'books', '/books', { tab: 'books' }, { isDefault: true }),
      v('track', 'books_followup', ['books.view', 'books.manage'], 'books', '/books', { tab: 'followup' }),
    ] },
    { key: 'packages', label: ['الباقات والقرعة', 'Packages & lottery'], modes: { packages: ['الباقات', 'Packages'], requests: ['الطلبات', 'Requests'], lottery: ['القرعة', 'Lottery'] }, views: [
      v('packages', 'packages', ['packages.view'], 'packages', '/packages', { tab: 'packages' }, { isDefault: true }),
      x('requests', 'registration_requests', ['registrations.view'], 'packages', '/packages', { tab: 'requests' }),
      v('lottery', 'lottery', ['lottery.view'], 'lottery', '/lottery'),
    ] },
  ] },
  { key: 'followup', path: '/followup', icon: 'evaluation', tabs: [
    { key: 'student', label: ['الطالب', 'Student'], modes: { details: ['التفاصيل', 'Details'], info: ['المعلومات', 'Information'] }, views: [
      v('details', 'student_details', ['students.view'], 'student_lookup', '/students/find', { view: 'details' }, { isDefault: true }),
      v('info', 'student_info', ['students.view'], 'student_lookup', '/students/find', { view: 'info' }),
    ] },
    { key: 'notes', label: ['الملاحظات', 'Notes'], modes: { record: REC, view: VIEW }, kindLabel: ['النوع', 'Type'],
      kinds: { students: ['الطلبة', 'Students'], general: ['عامة', 'General'], levels: ['المستويات', 'Levels'], subjects: ['مواد المستويات', 'Level subjects'] }, views: [
        v('record', 'student_notes', NOTES, 'notes', '/notes', { tab: 'students' }, { kind: 'students', isDefault: true }),
        v('record', 'general_notes', NOTES, 'notes', '/notes', { tab: 'general' }, { kind: 'general' }),
        v('record', 'level_notes', NOTES, 'notes', '/notes', { tab: 'levels' }, { kind: 'levels' }),
        v('record', 'level_subject_notes', NOTES, 'notes', '/notes', { tab: 'subjects' }, { kind: 'subjects' }),
        v('view', 'view_level_notes', NOTES, 'notes', '/notes', { tab: 'levels_view' }, { kind: 'levels' }),
        v('view', 'view_level_subject_notes', NOTES, 'notes', '/notes', { tab: 'subjects_view' }, { kind: 'subjects' }),
      ] },
    { key: 'divisions', label: ['التقسيمات', 'Divisions'], views: [v('main', 'divisions', ['lessons.view', 'divisions.manage'], 'divisions', '/divisions')] },
    { key: 'evaluation', label: ['التقييم', 'Evaluation'], modes: { record: REC, view: VIEW, criteria: ['المعايير', 'Criteria'] }, kindLabel: ['النوع', 'Type'],
      kinds: { daily: ['يومي', 'Daily'], monthly: ['شهري', 'Monthly'], class: ['الصف', 'Class'], division: ['التقسيم', 'Division'] }, views: [
        v('record', 'student_evaluation', ['evaluations.view', 'evaluations.record'], 'evaluation', '/evaluation', { tab: 'daily' }, { kind: 'daily', isDefault: true }),
        x('record', 'monthly_evaluation', ['evaluations.view', 'evaluations.record'], 'evaluation', '/evaluation', { tab: 'monthly' }, { kind: 'monthly' }),
        v('record', 'division_evaluation', ['evaluations.record'], 'division_evaluation', '/division-evaluation', { tab: 'record' }, { kind: 'division', isDefault: true }),
        v('view', 'view_student_evaluation', ['reports.view'], 'reports', '/reports', { report: 'evaluation' }, { kind: 'class' }),
        v('view', 'view_division_evaluation', ['evaluations.view'], 'division_evaluation', '/division-evaluation', { tab: 'view' }, { kind: 'division' }),
        v('criteria', 'evaluation_criteria', ['evaluation_criteria.manage'], 'evaluation_criteria', '/evaluation-criteria'),
      ] },
    { key: 'lessons', label: ['الدروس', 'Lessons'], modes: { quran: ['القرآن', 'Quran'], subjects: ['المواد', 'Subjects'] }, views: [
      v('quran', 'quran_lessons', ['evaluations.view'], 'quran_lessons', '/quran-lessons'),
      v('subjects', 'update_subject_lessons', ['subject_progress.record'], 'subject_progress', '/subject-progress'),
    ] },
  ] },
  { key: 'attendance', path: '/attendance', icon: 'attendance', tabs: [
    { key: 'students', label: ['الطلبة', 'Students'], modes: { record: REC, view: VIEW, monitor: MON }, kindLabel: ['النوع', 'Type'],
      kinds: { class: ['الصف', 'Class'], division: ['التقسيم', 'Division'] }, views: [
        v('record', 'student_attendance', ['attendance.view', 'attendance.record'], 'attendance', '/attendance', {}, { kind: 'class', isDefault: true }),
        v('record', 'division_attendance', ['attendance.record'], 'division_attendance', '/division-attendance', {}, { kind: 'division' }),
        v('view', 'view_student_attendance', ['reports.view'], 'reports', '/reports', { report: 'attendance' }, { kind: 'class' }),
        v('monitor', 'attendance_monitor', ['lessons.manage'], 'attendance_monitor', '/attendance-monitor', {}, { kind: 'class' }),
        v('monitor', 'division_attendance_monitor', ['attendance.view'], 'division_attendance_monitor', '/division-attendance-monitor', {}, { kind: 'division' }),
      ] },
    { key: 'supervisors', label: ['المشرفون', 'Supervisors'], modes: { record: REC, view: VIEW }, views: [
      v('record', 'supervisor_attendance', ['staff_attendance.record'], 'supervisor_attendance', '/supervisor-attendance', { tab: 'record' }, { isDefault: true }),
      v('view', 'view_supervisor_attendance', ['staff_attendance.view', 'staff_attendance.record'], 'supervisor_attendance', '/supervisor-attendance', { tab: 'view' }),
    ] },
    { key: 'teachers', label: ['المعلمون', 'Teachers'], modes: { view: VIEW, record: REC }, views: [
      v('view', 'view_teacher_attendance', ['staff_attendance.view', 'staff_attendance.record'], 'teacher_attendance', '/teacher-attendance', { tab: 'view' }, { isDefault: true }),
      x('record', 'record_teacher_attendance', ['staff_attendance.record'], 'teacher_attendance', '/teacher-attendance', { tab: 'record' }),
    ] },
  ] },
  ...['program', 'trip'].map((type) => {
    const t = (tab) => ({ tab })
    const page = type === 'program' ? 'activities_programs' : 'activities_trips'
    const path = type === 'program' ? '/programs' : '/trips'
    const f = (k) => (type === 'program' ? k : k.replace('program', 'trip'))
    const fees = { key: 'fees', label: ['الرسوم', 'Fees'], modes: { pay: ['الدفع', 'Pay'], track: TRACK }, views: [
      v('pay', f('program_fee_payment'), ['payments.record'], page, path, t('fee_payment')),
      v('track', f('program_fee_followup'), ['wallets.view'], page, path, t('fee_followup')),
    ] }
    const attendance = { key: 'attendance', label: ['الحضور', 'Attendance'], modes: { record: REC, track: TRACK }, views: [
      v('record', type === 'program' ? 'program_attendance' : 'trip_attendance', ['activities.attendance'], page, path, t('attendance')),
      v('track', f('program_attendance_followup'), [ACT], page, path, t('attendance_followup')),
    ] }
    const overview = { key: 'overview', label: ['نظرة عامة', 'Overview'], views: [v('main', type === 'program' ? 'programs' : 'trips', [ACT, 'activities.manage'], page, path, t('list'), { isDefault: true })] }
    if (type === 'trip') {
      return { key: 'trips', path, icon: 'pin', tabs: [
        overview,
        { key: 'registration', label: ['التسجيل', 'Registration'], views: [v('main', 'trip_registration', ['activities.register'], page, path, t('register'))] },
        fees, attendance,
      ] }
    }
    return { key: 'programs', path, icon: 'trophy', tabs: [
      overview,
      { key: 'registration', label: ['التسجيل', 'Registration'], modes: { register: ['تسجيل', 'Register'], list: ['المسجّلون', 'Registered'] }, views: [
        v('register', 'program_registration', ['activities.register'], page, path, t('register')),
        v('list', 'program_students', [ACT], page, path, t('students')),
      ] },
      fees,
      { key: 'book', label: ['الكتاب', 'Book'], modes: { deliver: ['التسليم', 'Deliver'], track: TRACK }, views: [
        v('deliver', 'program_book_delivery', ['activities.manage'], page, path, t('book_delivery')),
        v('track', 'program_book_followup', [ACT], page, path, t('book_followup')),
      ] },
      attendance,
      { key: 'evaluation', label: ['التقييم', 'Evaluation'], modes: { enter: ['إدخال', 'Enter'], view: VIEW }, views: [
        v('enter', 'program_evaluation', ['activities.evaluate'], page, path, t('evaluation')),
        v('view', 'view_program_evaluation', [ACT], page, path, t('evaluation_view')),
      ] },
    ] }
  }),
  { key: 'grades', path: '/grades', icon: 'exams', tabs: [
    { key: 'exams', label: ['الامتحانات', 'Exams'], modes: { list: ['القائمة', 'List'], upload: ['الرفع', 'Upload'] }, views: [
      v('list', 'exams', ['exams.view'], 'exams', '/exams', {}, { prefixes: ['/exams'] }),
      v('upload', 'upload_exam_grades', ['exams.grade'], 'upload_exam_grades', '/upload-exam-grades'),
    ] },
    // Approved rename: رصد الدرجات. تنزيل الدرجات is a button inside عرض (kind "download"), not a mode.
    { key: 'marking', label: ['رصد الدرجات', 'Grade entry'], modes: { record: ['رصد', 'Enter'], view: VIEW, monitor: MON }, kindStyle: 'button',
      kinds: { table: ['عرض الدرجات', 'View grades'], download: ['تنزيل الدرجات', 'Download grades'] }, views: [
        v('record', 'grades', ['grades.record'], 'gradebook', '/grades', { tab: 'record' }, { isDefault: true }),
        v('view', 'view_grades', ['grades.view'], 'gradebook', '/grades', { tab: 'view' }, { kind: 'table' }),
        v('view', 'download_grades', ['grades.view'], 'gradebook', '/grades', { tab: 'download' }, { kind: 'download' }),
        v('monitor', 'grade_submission_monitor', ['grades.manage'], 'grade_monitor', '/grade-submission-monitor'),
      ] },
    { key: 'distribution', label: ['التوزيع', 'Distribution'], modes: { distribution: ['التوزيع', 'Distribution'], required: ['الدروس المطلوبة', 'Required lessons'] }, views: [
      v('distribution', 'grade_distribution', ['grades.manage'], 'grade_distribution', '/grade-distribution'),
      v('required', 'required_lessons', ['grades.manage', 'exams.manage'], 'required_lessons', '/required-lessons'),
    ] },
  ] },
  { key: 'motivation', path: '/motivation', icon: 'medal', label: ['التحفيز', 'Motivation'], tabs: [
    { key: 'competitions', label: ['المسابقات', 'Competitions'], modes: { competitions: ['المسابقات', 'Competitions'], challenges: ['التحديات', 'Challenges'] }, views: [
      v('competitions', 'competitions', ['competitions.view'], 'competitions', '/competitions', {}, { isDefault: true, prefixes: ['/competitions'], from: 'programs' }),
      x('challenges', 'challenges', ['challenges.view'], 'competitions', '/competitions', { tab: 'challenges' }),
    ] },
    { key: 'honor', label: ['لوحة التميز', 'Honor board'], modes: { board: ['اللوحة', 'Board'], badges: ['الأوسمة', 'Badges'], top: ['المتفوقون', 'Top students'] }, views: [
      v('board', 'honor', ['honor.view'], 'honor', '/honor', {}, { isDefault: true, from: 'grades' }),
      x('badges', 'badges', ['honor.view'], 'honor', '/honor', { tab: 'badges' }),
      v('top', 'top_students', ['grades.view'], 'honor', '/honor', { tab: 'grades' }, { from: 'grades' }),
    ] },
    { key: 'certificates', label: ['الشهادات', 'Certificates'], views: [v('main', 'certificates', ['certificates.view'], 'certificates', '/certificates', {}, { from: 'grades' })] },
  ] },
  { key: 'communication', path: '/communication', icon: 'messages', tabs: [
    { key: 'messages', label: ['الرسائل', 'Messages'], modes: { log: ['السجل', 'Log'], inbox: ['الوارد', 'Inbox'], send: ['إرسال', 'Send'], templates: ['القوالب', 'Templates'], rules: ['القواعد', 'Rules'], whatsapp: ['واتساب', 'WhatsApp'] }, views: [
      v('log', 'messages', ['messages.view'], 'messages', '/messages', { tab: 'log' }, { isDefault: true, prefixes: ['/messages'] }),
      x('inbox', 'message_inbox', ['messages.view', 'attendance.record'], 'messages', '/messages', { tab: 'inbox' }),
      x('send', 'message_send', ['messages.send'], 'messages', '/messages', { tab: 'send' }),
      x('templates', 'message_templates', ['messages.view', 'messages.manage'], 'messages', '/messages', { tab: 'templates' }),
      x('rules', 'message_rules', ['messages.manage'], 'messages', '/messages', { tab: 'rules' }),
      x('whatsapp', 'whatsapp', ['whatsapp.status'], 'messages', '/messages', { tab: 'whatsapp' }),
    ] },
    // The one accepted exception to the no-repeat rule (approved): التواصل والتقارير › التقارير.
    { key: 'reports', label: ['التقارير', 'Reports'], views: [v('main', 'reports', ['reports.view'], 'reports', '/reports', { report: '' }, { isDefault: true })] },
    // Phase 8 (added at the merge): one screen; album pages (/gallery/:id) stay detail routes under this section.
    { key: 'gallery', label: ['معرض الصور', 'Photo gallery'], views: [v('main', 'gallery', ['gallery.view'], 'gallery', '/gallery', {}, { isDefault: true, prefixes: ['/gallery'] })] },
  ] },
]

/** Where each feature sat in the old menu (the other system's order), for the moves column. */
function oldSections() {
  const src = readFileSync(join(root, 'src/app/nav.ts'), 'utf8')
  const menu = src.slice(src.indexOf('export const MENU:'))
  const out = {}
  let section = null
  for (const line of menu.split('\n')) {
    const s = line.match(/key: '(\w+)', icon: '\w+', entries/)
    if (s) { section = s[1]; continue }
    const e = line.match(/soon\('(\w+)'/) ?? line.match(/\{ key: '(\w+)', path: /)
    if (e && section) out[e[1]] = section
  }
  return out
}

const slug = (k) => k.replace(/_/g, '-')
const json = (o) => `${JSON.stringify(o, null, 2)}\n`
const ar = JSON.parse(readFileSync(join(root, 'src/locales/ar/nav.json'), 'utf8'))
const en = JSON.parse(readFileSync(join(root, 'src/locales/en/nav.json'), 'utf8'))
const old = oldSections()
const errors = []

// ---- build the map
const features = []
const sections = SPEC.map((s) => ({
  key: s.key, path: s.path, icon: s.icon,
  tabs: s.tabs.map((tab) => {
    const views = tab.views.map((w) => {
      const id = w.feature ?? `extra:${w.extra}`
      const name = w.feature ? { ar: ar.menu?.[w.feature], en: en.menu?.[w.feature] } : { ar: EXTRA[w.extra]?.[0], en: EXTRA[w.extra]?.[1] }
      if (!name.ar || !name.en) errors.push(`no name for ${id}`)
      if (tab.views.length > 1 && !tab.modes?.[w.mode]) errors.push(`${s.key}.${tab.key}: mode ${w.mode} has no label`)
      if (w.kind && !tab.kinds?.[w.kind]) errors.push(`${s.key}.${tab.key}: kind ${w.kind} has no label`)
      const entry = {
        id, ...(w.feature ? { feature: w.feature } : { extra: w.extra }), name,
        mode: w.mode, ...(w.kind ? { kind: w.kind } : {}),
        permissions: w.perms, page: w.page,
        old: { path: w.path, query: w.query, ...(w.isDefault ? { isDefault: true } : {}), ...(w.prefixes ? { prefixes: w.prefixes } : {}) },
      }
      if (w.feature) {
        const was = old[w.feature]
        features.push({ feature: w.feature, name, section: s.key, tab: tab.key, mode: w.mode, kind: w.kind ?? null, permissions: w.perms, old: entry.old, from: was && was !== s.key ? was : null })
      }
      return entry
    })
    return { key: tab.key, path: `${s.path}/${slug(tab.key)}`, ...(tab.kindStyle ? { kindStyle: tab.kindStyle } : {}), views }
  }),
}))

// ---- count check: every old menu feature exactly once
const seen = new Map()
for (const f of features) seen.set(f.feature, (seen.get(f.feature) ?? 0) + 1)
for (const [k, n] of seen) if (n > 1) errors.push(`${k} appears ${n} times`)
for (const k of Object.keys(old)) if (!seen.has(k)) errors.push(`${k} (old ${old[k]}) is missing`)
for (const k of seen.keys()) if (!(k in old)) errors.push(`${k} is not an old menu feature`)
const oldCount = Object.keys(old).length
if (features.length !== oldCount) errors.push(`${features.length} features mapped, the old menu has ${oldCount}`)
if (errors.length) {
  console.error(`nav-map: ${errors.length} problem(s)\n  ${errors.join('\n  ')}`)
  process.exit(1)
}

const map = {
  $comment: 'Generated by frontend/scripts/nav-map.mjs from the approved nav_v2 table (docs/07-NAV-V2.md). Do not edit by hand.',
  version: 1,
  sections,
  features,
}

// ---- locale block (tab, mode, kind labels and extra names), one per language
function locale(lang) {
  const i = lang === 'ar' ? 0 : 1
  const out = { sections: {}, tabs: {}, modes: {}, kinds: {}, kindLabels: {}, extra: {} }
  for (const s of SPEC) {
    if (s.label) out.sections[s.key] = s.label[i]
    out.tabs[s.key] = {}; out.modes[s.key] = {}; out.kinds[s.key] = {}; out.kindLabels[s.key] = {}
    for (const t of s.tabs) {
      out.tabs[s.key][t.key] = t.label[i]
      if (t.modes) out.modes[s.key][t.key] = Object.fromEntries(Object.entries(t.modes).map(([k, l]) => [k, l[i]]))
      if (t.kinds) out.kinds[s.key][t.key] = Object.fromEntries(Object.entries(t.kinds).map(([k, l]) => [k, l[i]]))
      if (t.kindLabel) out.kindLabels[s.key][t.key] = t.kindLabel[i]
    }
  }
  for (const [k, l] of Object.entries(EXTRA)) out.extra[k] = l[i]
  return out
}

// ---- the table (.md)
function markdown() {
  const sec = (k) => ar.sections?.[k] ?? SPEC.find((s) => s.key === k)?.label?.[0] ?? k
  const lines = [
    '# nav_v2: navigation map (approved table)',
    '',
    '> Generated by `frontend/scripts/nav-map.mjs` from the same data as `frontend/src/app/nav-map.json`. Do not edit by hand.',
    '',
    `**Count check:** ${features.length} menu features mapped, ${oldCount} in the old menu, each exactly once ✔ (the other system's 79 plus our 11: الباقات، القرعة، لوحة التميز، الشهادات، المسابقات والتحديات، المستخدمين، الإعدادات، سجل التدقيق، الرسائل، التقارير، معرض الصور). ${Object.keys(EXTRA).length} more screens that were in-page tabs of the old pages are now modes too (marked *extra*).`,
    '',
    'Sidebar order (P6, with التحفيز after الدرجات): لوحة التحكم، ' + SPEC.map((s) => sec(s.key)).join('، ') + '.',
    '',
    'Roles in the default-mode column: A = المدير العام (super_admin), S = المشرف (supervisor), T = المعلم (teacher), with the seeded permissions. There is no المحاسب role; ولي الأمر and الطالب use the portal. "—" = the tab is hidden for that role.',
    '',
    '| # | Section | Tab (URL) | Feature (exact original name) | Mode `?mode=` | Kind `?kind=` | Permissions (any of) | Old URL | Old section → new |',
    '|---|---|---|---|---|---|---|---|---|',
  ]
  let n = 0
  for (const s of sections) {
    for (const t of s.tabs) {
      for (const w of t.views) {
        const f = w.feature ? features.find((x) => x.feature === w.feature) : null
        const q = new URLSearchParams(Object.entries(w.old.query).filter(([, val]) => val !== '')).toString()
        lines.push(`| ${w.feature ? ++n : '*extra*'} | ${sec(s.key)} | ${ar.sections?.[s.key] ? '' : ''}${locale('ar').tabs[s.key][t.key]} (\`${t.path}\`) | ${w.name.ar} | ${t.views.length > 1 ? `\`${w.mode}\`` : '—'} | ${w.kind ? `\`${w.kind}\`` : '—'} | ${w.permissions.map((p) => `\`${p}\``).join(' ')} | \`${w.old.path}${q ? `?${q}` : ''}\` | ${f?.from ? `${sec(f.from)} → ${sec(s.key)}` : ''} |`)
      }
    }
  }
  lines.push('', '## Default mode per role', '', '| Section › tab | A | S | T |', '|---|---|---|---|')
  const roles = rolePermissions()
  for (const s of sections) {
    for (const t of s.tabs) {
      const cell = (perms) => {
        const first = t.views.find((w) => w.permissions.some((p) => perms.has(p)))
        if (!first) return '—'
        return t.views.length > 1 ? `\`${first.mode}\`${first.kind ? ` / \`${first.kind}\`` : ''}` : '✓'
      }
      lines.push(`| ${sec(s.key)} › ${locale('ar').tabs[s.key][t.key]} | ${cell(roles.super_admin)} | ${cell(roles.supervisor)} | ${cell(roles.teacher)} |`)
    }
  }
  lines.push('', '## Moves (P5)', '', '| Feature | Old section → new section |', '|---|---|')
  for (const f of features.filter((x) => x.from)) lines.push(`| ${f.name.ar} | ${sec(f.from)} → ${sec(f.section)} |`)
  lines.push('', '## Hidden features (القائمة)', '',
    'Hiding is per tab in nav_v2: a tab is hidden when every feature it carries is hidden, and hiding a tab in القائمة hides all of its features (the old menu hides them too).',
    'A feature hidden in the old menu whose tab still has a visible feature shows again, as a mode. The القائمة screen lists those cases from the saved layout; with the default layout (nothing hidden) there are none. **Check production before switching nav_v2 on.**',
    '', '## Old URLs', '',
    'Router redirects (replace) take every old URL above to its new tab and mode, keep the other query values, and follow the runtime flag. `frontend/docker/nav-v2-redirects.conf` holds the same map as nginx 301s for the changed paths. Include it only once nav_v2 is permanent: nginx cannot read the runtime flag, so with the flag off it would send users to URLs that bounce back.',
    '')
  return lines.join('\n')
}

/** Seeded permissions per role, read from RolePermissionSeeder (for the default-mode column only). */
function rolePermissions() {
  const file = join(root, '../backend/database/seeders/RolePermissionSeeder.php')
  const out = { super_admin: new Set(), supervisor: new Set(), teacher: new Set() }
  if (!existsSync(file)) return out
  const src = readFileSync(file, 'utf8')
  const all = [...src.matchAll(/'([a-z_]+\.[a-z_]+)'/g)].map((m) => m[1])
  out.super_admin = new Set(all)
  for (const role of ['supervisor', 'teacher']) {
    const m = src.match(new RegExp(`'${role}'\\s*=>\\s*\\[([\\s\\S]*?)\\]`))
    if (m) out[role] = new Set([...m[1].matchAll(/'([a-z_]+\.[a-z_]+)'/g)].map((x) => x[1]))
  }
  return out
}

// ---- nginx 301s (every old path; the query picks the mode)
function nginx() {
  const byPath = new Map()
  for (const s of sections) for (const t of s.tabs) for (const w of t.views) {
    if (!byPath.has(w.old.path)) byPath.set(w.old.path, [])
    byPath.get(w.old.path).push({ tab: t, w, multi: t.views.length > 1 })
  }
  const target = ({ tab, w, multi }) => {
    const q = [multi ? `mode=${w.mode}` : '', w.kind ? `kind=${w.kind}` : ''].filter(Boolean).join('&')
    return `${tab.path}${q ? `?${q}` : ''}`
  }
  const out = ['# Generated by frontend/scripts/nav-map.mjs — nav_v2 old URL → new URL (301).',
    '# Include inside the server block of nginx.conf ONLY once nav_v2 is permanent (the runtime flag is not visible to nginx).', '']
  for (const [path, list] of byPath) {
    out.push(`location = ${path} {`)
    for (const c of list) {
      const [k, val] = Object.entries(c.w.old.query)[0] ?? []
      if (k && val) out.push(`    if ($arg_${k} = "${val}") { return 301 ${target(c)}${target(c).includes('?') ? '&' : '?'}$args; }`)
    }
    const fallback = list.find((c) => c.w.old.isDefault) ?? list[0]
    out.push(`    return 301 ${target(fallback)}$is_args$args;`, '}', '')
  }
  return out.join('\n')
}

// ---- write (or check)
const outputs = [
  ['src/app/nav-map.json', json(map)],
  ['../docs/07-NAV-V2.md', markdown()],
  ['docker/nav-v2-redirects.conf', nginx()],
]
for (const lang of ['ar', 'en']) {
  const file = `src/locales/${lang}/nav.json`
  const data = JSON.parse(readFileSync(join(root, file), 'utf8'))
  data.v2 = locale(lang)
  outputs.push([file, json(data)])
}
let stale = 0
for (const [file, content] of outputs) {
  const full = join(root, file)
  const current = existsSync(full) ? readFileSync(full, 'utf8') : ''
  if (current === content) continue
  if (check) { console.error(`nav-map: ${file} is out of date (run node scripts/nav-map.mjs)`); stale++ } else writeFileSync(full, content)
}
if (stale) process.exit(1)
console.log(`nav-map: ${features.length} features (each once), ${sections.length} sections, ${sections.reduce((n, s) => n + s.tabs.length, 0)} tabs${check ? ', up to date' : ', written'}`)
