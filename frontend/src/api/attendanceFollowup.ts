import { api } from './client'
import type { AttendanceStatus, SaveResult } from './attendance'

/** الحضور (Phase 5): division attendance and its monitor, the attendance monitor, supervisor and teacher attendance. */

export interface Ref { id: number; name: string }

// ── حضور التقسيم / مراقبة تسجيل حضور التقسيم ───────────────────────────────
export interface DivisionNight {
  id: number
  session_date: string
  start_time: string
  end_time: string
  attendance_taken: boolean
  lesson: Ref
  location: Ref | null
  divisions: { id: number; name: string; teacher: Ref | null; students: number; recorded: number; missing: number }[]
}
export interface DivisionNights { term: Ref; date: string; can_record: boolean; data: DivisionNight[] }
export interface DivisionSheet {
  session: { id: number; session_date: string; start_time: string; end_time: string; status: string; attendance_taken: boolean; lesson: Ref; location: Ref | null }
  division: { id: number; name: string; teacher: Ref | null }
  can_record: boolean
  data: { student: { id: number; full_name: string; student_no: string }; attendance: { status: AttendanceStatus; note: string | null } | null }[]
}

// ── مراقبة تسجيل الحضور ───────────────────────────────────────────────────
export interface MonitorTeacher { id: number; name: string; phone: string | null; whatsapp: string | null }
export interface MonitorRow {
  id: number
  session_date: string
  start_time: string
  end_time: string
  upcoming: boolean
  lesson: Ref
  level: Ref | null
  location: Ref | null
  teachers: MonitorTeacher[]
}
export interface MonitorPayload {
  term: Ref
  from: string
  to: string
  counts: { sessions: number; taken: number; not_taken: number }
  levels: Ref[]
  teachers: Ref[]
  data: MonitorRow[]
}

// ── حضور المشرفين والمعلمين ───────────────────────────────────────────────
export type StaffKind = 'supervisor' | 'teacher'
export type StaffStatus = 'present' | 'late' | 'absent' | 'excused'
export interface StaffRecord { id: number; status: StaffStatus; check_in: string | null; check_out: string | null; notes: string | null }
export interface Took { taken: number; total: number }
export interface StaffDayRow {
  user: { id: number; name: string; phone: string | null }
  expected: boolean
  record: StaffRecord | null
  sessions?: { id: number; lesson: Ref; start_time: string; end_time: string; attendance_taken: boolean }[]
  took_attendance?: Took
}
export interface StaffDay { term: Ref; date: string; kind: StaffKind; in_term: boolean; can_record: boolean; data: StaffDayRow[]; candidates: Ref[] }
export interface StaffSaveRecord { user_id: number; status: StaffStatus; check_in?: string | null; check_out?: string | null; notes?: string | null }
export interface StaffSummaryRow {
  user: Ref
  expected: number
  attended: number
  present: number
  late: number
  absent: number
  excused: number
  not_recorded: number
  rate: number | null
  sessions?: Took
}
export interface StaffSummary { term: Ref; kind: StaffKind; from: string; to: string; month: string | null; data: StaffSummaryRow[] }
export interface StaffDetail { user: Ref; kind: StaffKind; from: string; to: string; data: { date: string; expected: boolean; record: StaffRecord | null; took_attendance?: Took }[] }

export const attendanceFollowupApi = {
  divisionNights: (date: string) => api.get<DivisionNights>('/attendance/divisions', { params: { date } }).then((r) => r.data),
  divisionSheet: (divisionId: number, sessionId: number) => api.get<DivisionSheet>(`/attendance/divisions/${divisionId}/sessions/${sessionId}`).then((r) => r.data),
  saveDivision: (divisionId: number, sessionId: number, records: { student_id: number; status: AttendanceStatus; note?: string | null }[]) =>
    api.put<SaveResult>(`/attendance/divisions/${divisionId}/sessions/${sessionId}`, { records }).then((r) => r.data),

  monitor: (p: { from: string; to: string; level_id?: number; teacher_id?: number }) => api.get<MonitorPayload>('/attendance/monitor', { params: p }).then((r) => r.data),
  remind: (sessionId: number, teacherId: number) => api.post<{ message: string }>(`/attendance/monitor/${sessionId}/remind`, { teacher_id: teacherId }).then((r) => r.data),

  staffDay: (kind: StaffKind, date: string) => api.get<StaffDay>('/staff-attendance/day', { params: { kind, date } }).then((r) => r.data),
  saveStaffDay: (body: { academic_term_id: number; date: string; kind: StaffKind; records: StaffSaveRecord[] }) =>
    api.put<{ message: string; saved: number }>('/staff-attendance/day', body).then((r) => r.data),
  removeStaff: (id: number) => api.delete<{ message: string }>(`/staff-attendance/${id}`).then((r) => r.data),
  staffSummary: (kind: StaffKind, month?: string) => api.get<StaffSummary>('/staff-attendance/summary', { params: { kind, month: month || undefined } }).then((r) => r.data),
  staffDetail: (kind: StaffKind, userId: number, month?: string) =>
    api.get<StaffDetail>('/staff-attendance/detail', { params: { kind, user_id: userId, month: month || undefined } }).then((r) => r.data),
}
