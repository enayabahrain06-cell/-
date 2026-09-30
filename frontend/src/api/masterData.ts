import { api } from './client'

export interface AcademicTerm {
  id: number
  name: string
  name_ar: string
  name_en: string
  academic_year: string | null
  start_date: string | null
  end_date: string | null
  is_current: boolean
  legacy_label: string | null
  sort: number
  /** Only for users with terms.manage. */
  packages_count: number | null
  invoices_count: number | null
}

export interface Level {
  id: number
  name: string
  name_ar: string
  name_en: string
  code: string | null
  description: string | null
  sort: number
  is_active: boolean
  lessons_count: number | null
}

export interface Subject {
  id: number
  name: string
  name_ar: string
  name_en: string
  code: string | null
  description: string | null
  is_system: boolean
  sort: number
  is_active: boolean
}

export interface Night { id: number; weekday: string; label: string; is_active: boolean; start_time: string | null; end_time: string | null; notes: string | null }
export interface SupervisorRow { id: number; name: string; phone: string; track: string | null; is_active: boolean; nights: string[] }

export type TermInput = Pick<AcademicTerm, 'name_ar' | 'name_en' | 'academic_year' | 'start_date' | 'end_date'> & { is_current?: boolean }
export type LevelInput = Pick<Level, 'name_ar' | 'name_en' | 'code' | 'description' | 'sort' | 'is_active'>
export type SubjectInput = Pick<Subject, 'name_ar' | 'name_en' | 'code' | 'description' | 'sort' | 'is_active'>

type Saved<T> = { message: string; data: T }

export const termsApi = {
  list: () => api.get<{ data: AcademicTerm[]; current_id: number | null }>('/academic-terms').then((r) => r.data),
  create: (d: TermInput) => api.post<Saved<AcademicTerm>>('/academic-terms', d).then((r) => r.data),
  update: (id: number, d: TermInput) => api.put<Saved<AcademicTerm>>(`/academic-terms/${id}`, d).then((r) => r.data),
  makeCurrent: (id: number) => api.post<Saved<AcademicTerm>>(`/academic-terms/${id}/current`).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/academic-terms/${id}`).then((r) => r.data),
}

export const nightsApi = {
  list: () => api.get<{ data: Night[] }>('/nights').then((r) => r.data.data),
  update: (id: number, d: Partial<Night>) => api.put<{ message: string; data: Night }>(`/nights/${id}`, d).then((r) => r.data),
  supervisors: () => api.get<{ data: SupervisorRow[] }>('/master-data/supervisors').then((r) => r.data.data),
}

export const levelsApi = {
  list: (params: { active?: boolean } = {}) => api.get<{ data: Level[] }>('/levels', { params: params.active ? { active: 1 } : {} }).then((r) => r.data.data),
  create: (d: LevelInput) => api.post<Saved<Level>>('/levels', d).then((r) => r.data),
  update: (id: number, d: LevelInput) => api.put<Saved<Level>>(`/levels/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/levels/${id}`).then((r) => r.data),
}

export const subjectsApi = {
  list: (params: { active?: boolean } = {}) => api.get<{ data: Subject[] }>('/subjects', { params: params.active ? { active: 1 } : {} }).then((r) => r.data.data),
  create: (d: SubjectInput) => api.post<Saved<Subject>>('/subjects', d).then((r) => r.data),
  update: (id: number, d: SubjectInput) => api.put<Saved<Subject>>(`/subjects/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/subjects/${id}`).then((r) => r.data),
}
