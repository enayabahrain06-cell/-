import { api } from './client'

export interface DistLevel { id: number; name: string; sort: number; min_age: number | null; max_age: number | null; memorization_levels: string[] }
export interface DistClass { id: number; name: string; level_id: number | null; level: string | null; teacher: string | null; gender: string | null; capacity: number; free_seats: number }
export interface DistTerm { id: number; name: string; start_date: string | null; is_current: boolean }
export interface DistStudent {
  id: number
  full_name: string
  student_no: string
  gender: 'male' | 'female' | null
  status: string | null
  birth_date: string | null
  memorization_level: string | null
  memorization_level_label: string | null
  age: number | null
}
export interface CurrentPlace { lesson_id: number; lesson: string; level_id: number | null; level: string | null }
export interface PlaceStudent extends DistStudent { current: CurrentPlace | null; suggested_level: { id: number; name: string } | null }
export interface PlaceResult { student_id: number; full_name: string; ok: boolean; lesson: { id: number; name: string } | null; message: string }
export type Decision = 'promote' | 'repeat' | 'graduate'
export interface PromotionStudent extends DistStudent {
  lesson: { id: number; name: string } | null
  target_class: { id: number; name: string; level: string | null } | null
  decision: { decision: string; label: string; to_lesson: string | null; to_level: string | null } | null
}
export interface PromotionView {
  from_term: DistTerm
  to_term: DistTerm
  from_level: DistLevel
  to_level: DistLevel | null
  next_level_id: number | null
  promote_classes: DistClass[]
  repeat_classes: DistClass[]
  students: PromotionStudent[]
}
export interface LevelHistoryRow {
  id: number
  decision: string
  decision_label: string
  from_term: string | null
  from_level: string | null
  from_lesson: string | null
  to_term: string | null
  to_level: string | null
  to_lesson: string | null
  reason: string | null
  decided_by: string | null
  created_at: string | null
}
export interface LevelView { term: DistTerm; student: DistStudent; current: CurrentPlace | null; history: LevelHistoryRow[] }

type Results = { message: string; data: PlaceResult[] }

export const distributionApi = {
  options: () => api.get<{ data: { term: DistTerm; levels: DistLevel[]; classes: DistClass[] } }>('/distribution/options').then((r) => r.data.data),
  students: (p: { view: 'unplaced' | 'level'; level_id?: number; search?: string }) => api.get<{ data: PlaceStudent[] }>('/distribution/students', { params: p }).then((r) => r.data.data),
  place: (d: { academic_term_id: number; level_id: number; lesson_id: number | null; student_ids: number[] }) => api.post<Results>('/distribution/place', d).then((r) => r.data),
  promotion: (p: { from_term_id: number; from_level_id: number; to_term_id: number; to_level_id?: number }) =>
    api.get<{ data: PromotionView }>('/distribution/promotion', { params: { ...p, term_id: 'all' } }).then((r) => r.data.data),
  promote: (d: { from_term_id: number; from_level_id: number; to_term_id: number; to_level_id: number; mark_graduated: boolean; decisions: { student_id: number; decision: Decision; lesson_id: number | null; reason?: string | null }[] }) =>
    api.post<Results>('/distribution/promotion', d).then((r) => r.data),
  level: (studentId: number) => api.get<{ data: LevelView }>(`/distribution/level/${studentId}`).then((r) => r.data.data),
  changeLevel: (studentId: number, d: { academic_term_id: number; level_id: number; lesson_id: number | null; reason: string }) =>
    api.post<{ message: string; data: LevelHistoryRow }>(`/distribution/level/${studentId}`, d).then((r) => r.data),
}
