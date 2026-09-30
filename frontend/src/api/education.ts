import { api } from './client'

/** متابعة التعليم (Phase 4): notes, divisions, evaluation criteria, Quran lessons, subject lesson progress. */

export interface Ref { id: number; name: string }
export interface TermRef { id: number; name: string }
export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

// ── Notes (U9) ─────────────────────────────────────────────────────────────
export type NoteScope = 'student' | 'general' | 'level' | 'level_subject'
export interface LevelSubjectRef { id: number; level: Ref; subject: Ref }
export interface Note {
  id: number
  scope: NoteScope
  academic_term_id: number | null
  body: string
  pinned: boolean
  author: Ref | null
  student: { id: number; full_name: string; student_no: string } | null
  lesson: Ref | null
  level: Ref | null
  level_subject: LevelSubjectRef | null
  created_at: string | null
  updated_at: string | null
  can_edit: boolean
}
export interface NoteOptions {
  term: TermRef
  classes: (Ref & { level_id: number | null })[]
  levels: Ref[]
  level_subjects: LevelSubjectRef[]
  can_manage: boolean
  is_manager: boolean
}
export interface NoteFilters {
  scope: NoteScope
  student_id?: number
  lesson_id?: number
  level_id?: number
  level_subject_id?: number
  author?: 'mine'
  q?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}
export interface NewNote {
  academic_term_id: number
  scope: NoteScope
  body: string
  pinned?: boolean
  student_id?: number | null
  lesson_id?: number | null
  level_id?: number | null
  level_subject_id?: number | null
}
export interface NoteStudent { id: number; full_name: string; student_no: string; gender: string | null; notes_count: number; last_note_at: string | null }
export type TimelineKind = 'note' | 'issue' | 'issue_note' | 'attendance' | 'evaluation'
export interface TimelineItem {
  kind: TimelineKind
  key: string
  date: string
  at: string | null
  body: string
  author: string | null
  lesson: string | null
  meta: { id?: number; pinned?: boolean; can_edit?: boolean; term?: string | null; issue_id?: number; category?: string | null; status?: string | null; subject?: string | null; type?: string }
}

export const notesApi = {
  options: () => api.get<NoteOptions>('/notes/options').then((r) => r.data),
  list: (f: NoteFilters) => api.get<Paged<Note> & { term: TermRef }>('/notes', { params: f }).then((r) => r.data),
  students: (lessonId: number) => api.get<{ lesson: Ref; data: NoteStudent[] }>('/notes/students', { params: { lesson_id: lessonId } }).then((r) => r.data),
  create: (d: NewNote) => api.post<{ message: string; data: Note }>('/notes', d).then((r) => r.data),
  update: (id: number, d: { body?: string; pinned?: boolean }) => api.put<{ message: string; data: Note }>(`/notes/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/notes/${id}`).then((r) => r.data),
  timeline: (studentId: number) => api.get<{ data: TimelineItem[]; can_add: boolean }>(`/students/${studentId}/timeline`).then((r) => r.data),
}

// ── Divisions ──────────────────────────────────────────────────────────────
export interface Division { id: number; lesson_id: number; academic_term_id: number; name: string; sort: number; teacher: Ref | null; student_ids: number[] }
export interface DivisionStudent { id: number; full_name: string; student_no: string; division_id: number | null }
export interface DivisionsPayload {
  term: TermRef
  classes: (Ref & { level_id: number | null })[]
  teachers: Ref[]
  lesson?: Ref
  can_manage?: boolean
  divisions?: Division[]
  students?: DivisionStudent[]
}
export interface DivisionInput { academic_term_id?: number; lesson_id?: number; name: string; teacher_id: number | null; sort?: number | null }
export interface CriterionRow { id: number; key: string | null; name: string; max_score: number; weight: number; is_system: boolean }
export interface DivisionResults {
  division: { id: number; name: string; lesson: Ref }
  subjects: Ref[]
  subject_id: number
  criteria: CriterionRow[]
  data: { student: { id: number; full_name: string; student_no: string }; count: number; averages: Record<string, number | null>; percent: number | null }[]
  averages: Record<string, number | null>
  percent: number | null
}

export const divisionsApi = {
  list: (lessonId?: number) => api.get<DivisionsPayload>('/divisions', { params: { lesson_id: lessonId } }).then((r) => r.data),
  create: (d: DivisionInput) => api.post<{ message: string; data: Division }>('/divisions', d).then((r) => r.data),
  update: (id: number, d: Partial<DivisionInput>) => api.put<{ message: string; data: Division }>(`/divisions/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/divisions/${id}`).then((r) => r.data),
  assign: (id: number, studentIds: number[]) => api.put<{ message: string; data: Division }>(`/divisions/${id}/students`, { student_ids: studentIds }).then((r) => r.data),
  results: (id: number, p: { subject_id?: number; from?: string; to?: string; type?: 'daily' | 'monthly' }) =>
    api.get<DivisionResults>(`/divisions/${id}/evaluations`, { params: p }).then((r) => r.data),
}

// ── Evaluation criteria (U5) ───────────────────────────────────────────────
export interface Criterion {
  id: number; subject_id: number; key: string | null; name_ar: string; name_en: string; name: string
  max_score: number; weight: number; is_system: boolean; sort: number; is_active: boolean; in_use?: boolean
}
export interface CriterionInput { subject_id?: number; name_ar: string; name_en: string; max_score?: number; weight?: number; is_active?: boolean }
export interface CriteriaPayload {
  subjects: { id: number; name: string; code: string | null; is_active: boolean; criteria_count: number }[]
  subject_id: number
  data: Criterion[]
}

export const criteriaApi = {
  list: (subjectId?: number) => api.get<CriteriaPayload>('/evaluation-criteria', { params: { subject_id: subjectId } }).then((r) => r.data),
  create: (d: CriterionInput) => api.post<{ message: string; data: Criterion }>('/evaluation-criteria', d).then((r) => r.data),
  update: (id: number, d: Partial<CriterionInput>) => api.put<{ message: string; data: Criterion }>(`/evaluation-criteria/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/evaluation-criteria/${id}`).then((r) => r.data),
  reorder: (subjectId: number, ids: number[]) => api.post<{ message: string }>('/evaluation-criteria/reorder', { subject_id: subjectId, ids }).then((r) => r.data),
}

// ── دروس القرآن ────────────────────────────────────────────────────────────
export interface QuranLessonRow {
  id: number; date: string | null; type: 'memorized' | 'revised'
  student: { id: number; full_name: string; student_no: string } | null
  lesson: Ref | null
  surah_number: number; surah: string; from_ayah: number; to_ayah: number; ayah_count: number
  recorded_by: string | null; note: string | null
}
export interface QuranLessonFilters { lesson_id?: number; student_id?: number; type?: 'memorized' | 'revised'; from?: string; to?: string; page?: number }

export const quranLessonsApi = {
  list: (f: QuranLessonFilters) =>
    api.get<{ term: TermRef; classes: Ref[]; data: QuranLessonRow[]; meta: Paged<QuranLessonRow>['meta'] & { ayahs: number } }>('/quran-lessons', { params: f }).then((r) => r.data),
}

// ── تحديث دروس المواد ──────────────────────────────────────────────────────
export type PlanStatus = 'on_time' | 'late' | 'overdue' | 'upcoming'
export interface SubjectProgressRow {
  plan_item_id: number; week_no: number; title: string; notes: string | null
  week_starts_on: string | null; week_ends_on: string | null; status: PlanStatus
  progress: { id: number; taught_on: string | null; taught_by: string | null; lesson_session_id: number | null; session_date: string | null; notes: string | null } | null
}
export interface SubjectProgressPayload {
  term: TermRef & { start_date: string | null; end_date: string | null }
  classes: (Ref & { level_id: number | null })[]
  subjects?: (Ref & { level_subject_id: number })[]
  lesson?: Ref
  level_subject?: { id: number; subject: Ref; level: Ref } | null
  items?: SubjectProgressRow[]
  sessions?: { id: number; date: string }[]
  summary?: { total: number; taught: number; on_time: number; late: number; overdue: number; upcoming: number } | null
}

export const subjectProgressApi = {
  get: (p: { lesson_id?: number; subject_id?: number }) => api.get<SubjectProgressPayload>('/subject-progress', { params: p }).then((r) => r.data),
  mark: (d: { lesson_id: number; plan_item_id: number; taught_on: string; lesson_session_id?: number | null; notes?: string | null }) =>
    api.post<{ message: string }>('/subject-progress', d).then((r) => r.data),
  unmark: (id: number) => api.delete<{ message: string }>(`/subject-progress/${id}`).then((r) => r.data),
}
