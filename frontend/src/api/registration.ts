import { api } from './client'
import type { StudentSummary } from './students'

export type PackageGender = 'male' | 'female' | 'mixed'
export type RequestStatus = 'pending' | 'accepted' | 'enrolled' | 'pending_lottery' | 'waitlist' | 'rejected'

export interface Package {
  id: number
  name: string
  name_ar: string
  name_en: string
  description: string | null
  min_age: number
  max_age: number
  gender: PackageGender
  gender_label: string
  seats: number
  seats_taken: number
  seats_left: number
  is_full: boolean
  pending_count?: number
  waitlist_count?: number
  lessons_count?: number
  price_fils: number
  days: string[]
  start_time: string
  end_time: string
  start_date: string
  end_date: string | null
  term: string | null
  academic_term_id?: number | null
  academic_term?: { id: number; name: string } | null
  plan_ayahs: number
  memorization_direction: 'forward' | 'backward'
  status: 'draft' | 'open' | 'closed' | 'archived' | string
  status_label: string
  suitability?: { suitable: boolean; reason: 'closed' | 'age' | 'gender' | null; age_at_start: number; is_full: boolean; seats_left: number }
  /** Public registration only: the package's open placement test (null when it has none). */
  placement?: { name: string; duration_minutes: number; questions_count: number } | null
}

/** Placement test result as the family sees it: right/wrong per question, never the answer key. */
export interface PlacementResult {
  exam_name: string
  attempt_no: number | null
  status: 'submitted' | 'graded' | 'expired' | string
  submitted_at: string | null
  total_questions: number
  correct: number
  incorrect: number
  score: number
  total_marks: number
  percent: number
  recommended_level: string | null
  recommended_level_label: string | null
  questions: { position: number; question_id: number; prompt: string; type: string; answered: boolean; is_correct: boolean }[]
}

/** Short placement summary on a registration request (staff). */
export type PlacementSummary = Omit<PlacementResult, 'questions'> & { attempt_id: number }

/** GET/POST /public/placement…: an attempt in progress (questions, saved answers, timer) or its result. */
export interface PlacementState {
  token?: string
  exam: { name: string; duration_minutes: number; questions_count: number }
  status: 'in_progress' | 'submitted' | 'graded' | 'expired' | string
  attempt?: import('./exams').Attempt
  result?: PlacementResult
}

export interface PublicSettings {
  authority: { name_ar: string; name_en: string; address_ar: string | null; address_en: string | null; phone: string | null }
  country_code: string
  currency: string
  show_hijri: boolean
  default_locale: 'ar' | 'en'
  registration_open: boolean
  photo_required: boolean
  memorization_levels: { value: string; label: string }[]
}

export interface RegistrationRequest {
  id?: number
  request_no: string
  status: RequestStatus
  status_label: string
  waitlist_position: number | null
  package?: { id: number; name: string; start_date: string | null; price_fils: number }
  full_name: string
  birth_date?: string | null
  age_at_start: number
  gender: 'male' | 'female'
  /** Staff only: the nine-digit CPR, copied to the student on acceptance. */
  cpr?: string | null
  address?: string | null
  student_phone?: string | null
  guardian_name?: string
  guardian_phone?: string
  memorization_level: string
  memorization_level_label: string
  /** Staff only. Recommended by the placement test; final = what staff confirmed on acceptance. */
  recommended_level?: string | null
  recommended_level_label?: string | null
  final_level?: string | null
  final_level_label?: string | null
  level_confirmed_by?: string | null
  level_confirmed_at?: string | null
  placement?: PlacementSummary
  locale: 'ar' | 'en'
  has_photo?: boolean
  reason: string | null
  notes?: string | null
  decided_by?: string | null
  decided_at: string | null
  student?: StudentSummary
  created_at: string
}

/** A circle as CircleMatcher::present returns it: whether the student fits, and why not. */
export interface MatchedCircle {
  id: number
  name: string
  package: { id: number; name: string } | null
  age_group: { id: number; name: string } | null
  min_age: number | null
  max_age: number | null
  teacher: string | null
  location: string | null
  days: string[]
  start_time: string
  end_time: string
  capacity: number
  free_seats: number
  fits: boolean
  reason: string | null
  reason_label: string | null
  student_age: number | null
  same_group: boolean
}

export interface SubmitResult { message: string; request_no: string; status: RequestStatus; waitlist_position: number | null; track_url: string }

export interface PackageInput {
  name: string
  name_ar?: string | null
  name_en?: string | null
  description?: string | null
  min_age: number
  max_age: number
  gender: PackageGender
  seats: number
  price: string
  days: string[]
  start_time: string
  end_time: string
  start_date: string
  end_date?: string | null
  term?: string | null
  academic_term_id?: number | null
  plan_ayahs?: number | null
  memorization_direction: 'forward' | 'backward'
  status: string
}

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

export const publicApi = {
  settings: () => api.get<PublicSettings>('/public/settings').then((r) => r.data),
  packages: (gender: string, birthDate?: string) => api.get<{ data: Package[] }>('/public/packages', { params: { gender, ...(birthDate ? { birth_date: birthDate } : {}) } }).then((r) => r.data.data),
  submit: (form: FormData) => api.post<SubmitResult>('/public/registrations', form).then((r) => r.data),
  track: (requestNo: string, phone: string) => api.get<{ data: RegistrationRequest }>(`/public/registrations/${encodeURIComponent(requestNo)}`, { params: { phone } }).then((r) => r.data.data),
}

/** Placement test during registration. Only the start call is keyed by package; everything else by the token. */
export const placementApi = {
  start: (d: { package_id: number; full_name: string; guardian_phone: string }) => api.post<{ data: PlacementState }>('/public/placement', d).then((r) => r.data.data),
  show: (token: string) => api.get<{ data: PlacementState }>(`/public/placement/${encodeURIComponent(token)}`).then((r) => r.data.data),
  save: (token: string, answers: { question_id: number; answer: unknown }[]) =>
    api.put<{ saved_at: string; remaining_seconds: number }>(`/public/placement/${encodeURIComponent(token)}/answers`, { answers }).then((r) => r.data),
  submit: (token: string) => api.post<{ data: { status: string; result: PlacementResult } }>(`/public/placement/${encodeURIComponent(token)}/submit`).then((r) => r.data.data),
}

export const packagesApi = {
  list: (params: Record<string, string | number | undefined> = {}) => api.get<Paged<Package>>('/packages', { params: { per_page: 100, ...params } }).then((r) => r.data),
  create: (d: PackageInput) => api.post<{ data: Package }>('/packages', d).then((r) => r.data),
  update: (id: number, d: Partial<PackageInput>) => api.put<{ data: Package }>(`/packages/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete(`/packages/${id}`),
}

export const requestsApi = {
  list: (params: Record<string, string | number | undefined>) => api.get<Paged<RegistrationRequest>>('/registrations', { params }).then((r) => r.data),
  show: (id: number) => api.get<{ data: RegistrationRequest }>(`/registrations/${id}`).then((r) => r.data.data),
  /** Enroll into a circle (lesson_id) or take the lottery path; final_level is the level staff confirm. */
  accept: (id: number, d: { lesson_id?: number; lottery?: boolean; force?: boolean; final_level?: string | null } = {}) =>
    api.post(`/registrations/${id}/accept`, d).then((r) => r.data),
  /** Circles the request's student could join, with fit and free seats (CircleMatcher). */
  circles: (id: number) => api.get<{ data: MatchedCircle[]; recommended_id: number | null }>(`/registrations/${id}/circles`).then((r) => r.data),
  waitlist: (id: number, note?: string) => api.post(`/registrations/${id}/waitlist`, { note }).then((r) => r.data),
  reject: (id: number, reason: string) => api.post(`/registrations/${id}/reject`, { reason }).then((r) => r.data),
  /** Correct an undecided request (for example from the ID card); age and gender are re-checked against the package. */
  update: (id: number, d: { full_name?: string; birth_date?: string; gender?: 'male' | 'female'; cpr?: string; address?: string }) =>
    api.put<{ data: RegistrationRequest }>(`/registrations/${id}`, d).then((r) => r.data.data),
  /** Set the request's photo (for example the ID card photo); it moves to the student on acceptance. */
  photo: (id: number, file: File) => { const f = new FormData(); f.append('photo', file); return api.post<{ data: RegistrationRequest }>(`/registrations/${id}/photo`, f).then((r) => r.data.data) },
  bulkAccept: (d: { package_id: number; statuses?: string[]; gender?: string }) =>
    api.post<{ message: string; accepted: { id: number; request_no: string }[]; skipped: { id: number; request_no: string; reason: string }[]; seats_left: number }>('/registrations/bulk-accept', d).then((r) => r.data),
}
