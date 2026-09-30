import { api } from './client'
import type { ProgressEntry } from './attendance'
import type { ScoreRow, StudentSummary } from './students'

export type Criterion = 'memorization' | 'tajweed' | 'revision' | 'behavior'
export const CRITERIA: Criterion[] = ['memorization', 'tajweed', 'revision', 'behavior']

export interface SavedScore extends ScoreRow {
  student_id: number
  sent_to_guardian_at: string | null
  subject_id?: number | null
  division_id?: number | null
  /** U5: score per criterion id (Quran's four system criteria mirror the columns). */
  scores?: Record<string, number>
}

/** An active criterion of the evaluated subject (التقييمات). */
export interface SheetCriterion { id: number; key: string | null; name: string; max_score: number; weight: number; is_system: boolean }

export interface SheetRow {
  student: StudentSummary
  evaluation: SavedScore | null
}

export interface Sheet {
  session_id: number
  date: string
  start_time: string
  end_time: string
  lesson: { id: number; name: string; teacher: string | null }
  location: string | null
  threshold: number
  subject?: { id: number; name: string; code: string | null; is_quran: boolean } | null
  subjects?: { id: number; name: string; code: string | null }[]
  criteria?: SheetCriterion[]
  division?: { id: number; name: string } | null
  divisions?: { id: number; name: string }[]
  data: SheetRow[]
}

/** One student's scores for a subject other than Quran (or Quran with extra criteria): criterion id → score. */
export interface CriteriaEntry {
  student_id: number
  memorization?: number
  tajweed?: number
  revision?: number
  behavior?: number
  scores: Record<number, number>
  note?: string | null
}

export interface Suggestion {
  student_id: number
  evaluation_id: number
  lesson_id: number
  criterion: Criterion
  score: number
  category: string
  category_label: string
  message: string
}

export interface EvaluationEntry {
  student_id: number
  memorization: number
  tajweed: number
  revision: number
  behavior: number
  note?: string | null
  progress?: ProgressEntry[]
}

export interface Option { value: string; label: string }
export interface IssueOptions { categories: Option[]; tajweed_aspects: Option[]; severities: Option[]; statuses: Option[] }

export interface NewIssue {
  category: string
  subcategory?: string | null
  description: string
  action_plan?: string | null
  severity: string
  next_follow_up_date?: string | null
  evaluation_id?: number | null
  lesson_id?: number | null
}

export const evaluationsApi = {
  /** Subjects this user may evaluate in the session's class (a subject teacher who does not teach Quran there). */
  subjects: (sessionId: number) => api.get<{ data: { id: number; name: string; code: string | null }[] }>(`/sessions/${sessionId}/evaluation-subjects`).then((r) => r.data.data),
  sheet: (sessionId: number, p: { subject_id?: number; division_id?: number } = {}) => api.get<Sheet>(`/sessions/${sessionId}/evaluations`, { params: p }).then((r) => r.data),
  saveDaily: (sessionId: number, entries: EvaluationEntry[], divisionId?: number | null) =>
    api.post<{ message: string; data: SavedScore[]; suggested_issues: Suggestion[] }>(`/sessions/${sessionId}/evaluations`, { entries, division_id: divisionId ?? undefined }).then((r) => r.data),
  saveCriteria: (sessionId: number, subjectId: number, entries: CriteriaEntry[], divisionId?: number | null) =>
    api.post<{ message: string; data: SavedScore[]; suggested_issues: Suggestion[] }>(`/sessions/${sessionId}/evaluations`, { subject_id: subjectId, entries, division_id: divisionId ?? undefined }).then((r) => r.data),
  monthlyList: (lessonId: number, period: string) =>
    api.get<{ data: (SavedScore & { student: StudentSummary })[] }>('/evaluations', { params: { lesson_id: lessonId, type: 'monthly', period, per_page: 200 } }).then((r) => r.data.data),
  saveMonthly: (lessonId: number, period: string, entries: EvaluationEntry[]) =>
    api.post<{ message: string; data: SavedScore[]; suggested_issues: Suggestion[] }>(`/lessons/${lessonId}/evaluations/monthly`, { period, entries }).then((r) => r.data),
  send: (evaluationId: number) => api.post<{ message: string; queued: number }>(`/evaluations/${evaluationId}/send`).then((r) => r.data),
  issueOptions: () => api.get<IssueOptions>('/issues/options').then((r) => r.data),
  openIssue: (studentId: number, issue: NewIssue) => api.post(`/students/${studentId}/issues`, issue).then((r) => r.data),
}
