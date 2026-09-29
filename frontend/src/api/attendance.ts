import { api } from './client'
import type { StudentSummary } from './students'

export type AttendanceStatus = 'present' | 'late' | 'absent' | 'excused'

export interface SessionInfo {
  id: number
  lesson_id: number
  lesson: { id: number; name: string; teacher: { id: number; name: string } | null; default_location_id: number | null; package?: { id: number; name: string } | null } | null
  session_date: string
  start_time: string
  end_time: string
  location_id: number | null
  location: { id: number; name: string; map_link: string | null } | null
  is_location_override: boolean
  status: 'scheduled' | 'held' | 'cancelled'
  status_label: string
  attendance_taken: boolean
  attendance_summary?: { present: number; absent: number }
  notes?: string | null
}

export interface RosterRow {
  student: StudentSummary
  current_memorization: string | null
  current_revision: string | null
  attendance: { id: number; status: AttendanceStatus; memorization_assignment: string | null; revision_assignment: string | null; note: string | null; absence_notified_at: string | null } | null
}

export interface ProgressEntry {
  type: 'memorized' | 'revised'
  surah_number: number
  from_ayah: number
  to_ayah: number
}

export interface AttendanceRecord {
  student_id: number
  status: AttendanceStatus
  memorization_assignment?: string | null
  revision_assignment?: string | null
  note?: string | null
  progress?: ProgressEntry[]
}

export interface SaveResult {
  message: string
  saved: number
  absent: number
  repeated_absence_alerts: number[]
}

export interface Surah {
  number: number
  name: string
  name_ar: string
  name_en: string
  ayah_count: number
  juz_start: number
}

export const attendanceApi = {
  sessionsOn: (date: string) => api.get<{ data: SessionInfo[] }>('/sessions/today', { params: { date } }).then((r) => r.data.data),
  roster: (sessionId: number) => api.get<{ session: SessionInfo; roster: RosterRow[] }>(`/sessions/${sessionId}/attendance`).then((r) => r.data),
  save: (sessionId: number, records: AttendanceRecord[]) => api.put<SaveResult>(`/sessions/${sessionId}/attendance`, { records }).then((r) => r.data),
  markAllPresent: (sessionId: number) => api.post<SaveResult>(`/sessions/${sessionId}/attendance/mark-all-present`).then((r) => r.data),
}

export const quranApi = {
  surahs: () => api.get<{ data: Surah[] }>('/quran/surahs').then((r) => r.data.data),
}
