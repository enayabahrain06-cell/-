import { api } from './client'

export type DayKey = 'sat' | 'sun' | 'mon' | 'tue' | 'wed' | 'thu' | 'fri'

export interface MonthNumbers {
  sessions_due: number
  held: number
  cancelled: number
  taken_rate: number | null
  on_time_rate: number | null
  evaluations: number
  avg_evaluation: number | null
  attendance_rate: number | null
}

export interface TeacherRow {
  id: number
  name: string
  phone: string | null
  gender: 'male' | 'female' | null
  specialization: string | null
  is_active: boolean
  active_circles: number
  active_students: number
  month: MonthNumbers
  /** 96 px thumbnail, signed for 10 minutes; null without a photo. */
  photo_url: string | null
}

export interface TeacherFilters {
  search?: string
  gender?: string
  active?: '1' | '0'
  page?: number
  per_page?: number
}

export interface TeacherCircle {
  id: number
  name: string
  package: string | null
  status: 'active' | 'paused' | 'ended'
  gender: string | null
  days: DayKey[]
  start_time: string
  end_time: string
  location: string | null
  students: number
  capacity: number | null
}

export interface TimetableDay {
  day: DayKey
  items: { lesson_id: number; name: string; start_time: string; end_time: string; location: string | null }[]
}

export interface UpcomingSession {
  id: number
  lesson_id: number
  lesson: string | null
  date: string
  start_time: string
  end_time: string
  location: string | null
  attendance_taken: boolean
}

export interface TeacherDetail {
  id: number
  name: string
  phone: string | null
  email: string | null
  gender: 'male' | 'female' | null
  specialization: string | null
  bio: string | null
  /** Signed 512 px / 96 px photo URLs (10 minutes); null without a photo. */
  photo: { profile: string | null; thumb: string | null }
  is_active: boolean
  last_login_at: string | null
  active_students: number
  month: MonthNumbers
  evaluations_30d: number
  circles: TeacherCircle[]
  timetable: TimetableDay[]
  upcoming: UpcomingSession[]
  can: { edit: boolean; edit_account: boolean; take_attendance: boolean }
}

export const teachersApi = {
  list: (f: TeacherFilters) =>
    api
      .get<{ data: TeacherRow[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }>('/teachers', { params: { ...f, stats: 1 } })
      .then((r) => r.data),
  show: (id: number) => api.get<{ data: TeacherDetail }>(`/teachers/${id}`).then((r) => r.data.data),
  update: (id: number, body: { specialization: string | null; bio: string | null }) =>
    api.put<{ message: string }>(`/teachers/${id}`, body).then((r) => r.data),
  uploadPhoto: (id: number, file: File) => { const f = new FormData(); f.append('photo', file); return api.post<{ data: TeacherDetail['photo'] }>(`/teachers/${id}/photo`, f).then((r) => r.data.data) },
  removePhoto: (id: number) => api.delete<{ message: string }>(`/teachers/${id}/photo`).then((r) => r.data),
}
