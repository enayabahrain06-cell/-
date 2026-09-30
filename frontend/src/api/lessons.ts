import { api } from './client'
import type { SessionInfo } from './attendance'
import type { StudentSummary } from './students'

export type TrackGender = 'male' | 'female' | 'mixed'
export type HallGender = 'male' | 'female' | 'shared'

export interface Lesson {
  id: number
  gender: TrackGender | null
  name: string
  package_id: number
  package?: { id: number; name: string; gender: TrackGender }
  teacher_id: number
  teacher?: { id: number; name: string; phone: string }
  location_id: number | null
  location?: { id: number; name: string; map_link: string | null } | null
  days: string[]
  days_labels: string[]
  start_time: string
  end_time: string
  capacity: number
  student_count: number
  start_date: string
  end_date: string | null
  status: 'active' | 'paused' | 'ended'
  status_label: string
  level_id?: number | null
  level?: { id: number; name: string } | null
  students?: { id: number; student: StudentSummary; status: string; joined_at: string | null; current_memorization: string | null }[]
  next_sessions?: SessionInfo[]
  /** Detail view only: the schedule from الجدول الدراسي; `editable` false = change it in the timetable. */
  schedule?: { source: 'timetable' | 'legacy'; editable: boolean; periods: number; nights: { weekday: string; start: string; end: string; location_id: number | null }[] }
  /** Detail view only: whether this user may add students here (LessonPolicy::addStudents). */
  can_add_students?: boolean
}

/** Why a searched student cannot join the circle (server-side rules; see LessonService::ineligibility). */
export type CandidateReason = 'inactive' | 'gender' | 'age' | 'already_in' | 'full' | 'other_circle_locked'

export interface LessonCandidate {
  id: number
  student_no: string
  full_name: string
  initial: string
  gender: 'male' | 'female' | null
  photo_url: string | null
  age_at_start: number | null
  circles: { id: number; name: string; teacher: string | null; movable: boolean }[]
  reason: CandidateReason | null
  action: 'add' | 'move' | null
}

export interface Conflict {
  kind: 'lesson' | 'session' | 'override' | 'booking'
  id: number
  title: string
  date: string | null
  days?: string[]
  start_time: string
  end_time: string
}

export interface Hall {
  id: number
  gender: HallGender
  name: string
  code: string | null
  address: string | null
  map_link: string | null
  capacity: number
  is_active: boolean
  notes: string | null
  lessons_count?: number
}

export interface CalendarItem {
  kind: 'session' | 'override' | 'booking' | 'occupied'
  gender: TrackGender | null
  id: number | null
  lesson_id: number | null
  title: string
  teacher: string | null
  date: string
  start_time: string
  end_time: string
  status: string | null
  masked: boolean
}

export interface Booking {
  id: number
  gender: 'male' | 'female' | null
  location_id: number
  location?: { id: number; name: string }
  title: string
  source: string
  booking_date: string
  start_time: string
  end_time: string
}

export interface PackageOption { id: number; name: string; gender: TrackGender; days: string[]; start_time: string; end_time: string; start_date: string; end_date: string | null; status: string }
export interface TeacherOption { id: number; name: string; phone: string; gender: 'male' | 'female' | null; specialization: string | null; active_circles: number }

export interface LessonInput {
  name: string
  package_id: number
  teacher_id: number
  location_id: number | null
  days: string[]
  start_time: string
  end_time: string
  capacity: number
  start_date: string
  end_date: string | null
  status: string
  /** Optional study level above the circle. */
  level_id?: number | null
}

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

export const lessonsApi = {
  list: (params: Record<string, string | number | undefined>) => api.get<Paged<Lesson>>('/lessons', { params }).then((r) => r.data),
  show: (id: number) => api.get<{ data: Lesson }>(`/lessons/${id}`).then((r) => r.data.data),
  create: (d: LessonInput) => api.post<{ data: Lesson; conflicts: Conflict[] }>('/lessons', d).then((r) => r.data),
  update: (id: number, d: Partial<LessonInput>) => api.put<{ data: Lesson; conflicts: Conflict[] }>(`/lessons/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete(`/lessons/${id}`),
  conflicts: (id: number) => api.get<{ conflicts: Conflict[] }>(`/lessons/${id}/conflicts`).then((r) => r.data.conflicts),
  sessions: (id: number, from?: string, to?: string) => api.get<{ data: SessionInfo[] }>(`/lessons/${id}/sessions`, { params: { from, to } }).then((r) => r.data.data),
  candidates: (id: number, search: string) => api.get<{ data: LessonCandidate[]; free_seats: number }>(`/lessons/${id}/candidates`, { params: { search } }).then((r) => r.data),
  enroll: (id: number, studentIds: number[], move = false) =>
    api.post<{ message: string; added: number[]; moved: { student_id: number; from_lesson_id: number }[] }>(`/lessons/${id}/students`, { student_ids: studentIds, move }).then((r) => r.data),
  unenroll: (id: number, studentId: number) => api.delete(`/lessons/${id}/students/${studentId}`),
  changeLocation: (id: number, d: { mode: 'one_day' | 'all_upcoming'; date?: string | null; location_id: number; notify: boolean; reason?: string | null }) =>
    api.post<{ message: string; notified: number; dates: string[] }>(`/lessons/${id}/change-location`, d).then((r) => r.data),
}

export const hallsApi = {
  list: (params: Record<string, string | number | boolean | undefined> = {}) => api.get<{ data: Hall[] }>('/locations', { params: { all: 1, ...params } }).then((r) => r.data.data),
  create: (d: Partial<Hall>) => api.post<{ data: Hall }>('/locations', d).then((r) => r.data.data),
  update: (id: number, d: Partial<Hall>) => api.put<{ data: Hall }>(`/locations/${id}`, d).then((r) => r.data.data),
  toggle: (id: number) => api.post(`/locations/${id}/toggle`),
  remove: (id: number) => api.delete(`/locations/${id}`),
  free: (p: { date: string; start_time: string; end_time: string; ignore_lesson_id?: number; gender?: string }) => api.get<{ data: Hall[] }>('/locations/free', { params: p }).then((r) => r.data.data),
  calendar: (id: number, from: string, to: string) => api.get<{ location: { data: Hall } | Hall; from: string; to: string; items: CalendarItem[] }>(`/locations/${id}/calendar`, { params: { from, to } }).then((r) => r.data),
}

export const bookingsApi = {
  list: (params: Record<string, string | number | undefined>) => api.get<Paged<Booking>>('/location-bookings', { params }).then((r) => r.data),
  create: (d: { location_id: number; title: string; booking_date: string; start_time: string; end_time: string; gender: string }) => api.post<{ data: Booking }>('/location-bookings', d).then((r) => r.data),
  remove: (id: number) => api.delete(`/location-bookings/${id}`),
}

export const optionsApi = {
  packages: () => api.get<{ data: PackageOption[] }>('/packages', { params: { per_page: 200 } }).then((r) => r.data.data),
  teachers: (gender?: string) => api.get<{ data: TeacherOption[] }>('/teachers', { params: gender ? { gender } : {} }).then((r) => r.data.data),
}
