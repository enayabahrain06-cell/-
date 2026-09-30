import { api } from './client'
import type { StudentSummary } from './students'

export type ActivityType = 'program' | 'trip'
export type ActivityStatus = 'draft' | 'open' | 'closed' | 'done'
export type RegistrationStatus = 'registered' | 'waitlist' | 'cancelled'
export type ActivityAttendanceStatus = 'present' | 'absent' | 'late' | 'excused'
export type MoneyStatus = 'paid' | 'partial' | 'unpaid' | 'none'

export interface Activity {
  id: number
  type: ActivityType
  academic_term_id: number
  name: string
  name_ar: string
  name_en: string | null
  description: string | null
  starts_on: string
  ends_on: string | null
  start_time: string | null
  end_time: string | null
  location: { id: number; name: string } | null
  place: string | null
  seats: number | null
  price_fils: number
  gender: 'male' | 'female' | 'mixed' | null
  min_age: number | null
  max_age: number | null
  level: { id: number; name: string } | null
  has_book: boolean
  book_title: string | null
  book_price_fils: number
  status: ActivityStatus
  registered_count: number
  waitlist_count: number
}

export interface ActivityInput {
  type?: ActivityType
  academic_term_id?: number
  name_ar: string
  name_en: string | null
  description: string | null
  starts_on: string
  ends_on: string | null
  start_time: string | null
  end_time: string | null
  location_id: number | null
  place: string | null
  seats: number | null
  price_fils: number
  gender: string | null
  min_age: number | null
  max_age: number | null
  level_id: number | null
  has_book: boolean
  book_title: string | null
  book_price_fils: number
  status: ActivityStatus
}

export interface ActivityOptions {
  levels: { id: number; name: string }[]
  rooms: { id: number; name: string }[]
  classes: { id: number; name: string; level: string | null }[]
}

export interface ActivityCan {
  manage: boolean
  register: boolean
  attendance: boolean
  evaluate: boolean
  record_payment: boolean
  money: boolean
}

export interface ActivityInvoice {
  id: number
  invoice_no: string
  amount_fils: number
  paid_fils: number
  remaining_fils: number
  status: 'open' | 'partial' | 'paid' | 'cancelled'
  due_date: string | null
}

export interface Tally { present: number; late: number; absent: number; excused: number; recorded: number; rate: number | null }

export interface RosterRow {
  id: number
  status: RegistrationStatus
  registered_at: string | null
  student: StudentSummary
  class: { id: number; name: string; level: string | null } | null
  fee_invoice: ActivityInvoice | null
  book_invoice: ActivityInvoice | null
  money: { total_fils: number; paid_fils: number; remaining_fils: number; status: MoneyStatus; overdue: boolean } | null
  book: { id: number; delivered_at: string | null; delivered_by: string | null; notes: string | null } | null
  attendance: Tally
  evaluation: { score: number | null; grade: string | null; notes: string | null; evaluated_by: string | null } | null
}

export interface Roster {
  activity: Activity
  data: RosterRow[]
  totals: {
    registered: number
    waitlist: number
    seats_left: number | null
    delivered: number
    evaluated: number
    money: { total_fils: number; paid_fils: number; remaining_fils: number; by_status: Record<MoneyStatus, number> } | null
  }
  can_remind: boolean
}

export type Outcome = 'register' | 'waitlist' | 'already' | 'refused'
export interface Candidate { student: StudentSummary; outcome: Outcome; reason: string | null }

export interface AttendanceSheet {
  activity: Activity
  date: string
  dates: string[]
  data: { student: StudentSummary; status: ActivityAttendanceStatus | null; notes: string | null }[]
}

export const activitiesApi = {
  list: (type: ActivityType) =>
    api.get<{ term: { id: number; name: string }; data: Activity[]; options: ActivityOptions; can: ActivityCan }>('/activities', { params: { type } }).then((r) => r.data),
  create: (d: ActivityInput) => api.post<{ message: string; data: Activity }>('/activities', d).then((r) => r.data),
  update: (id: number, d: Partial<ActivityInput>) => api.put<{ message: string; data: Activity }>(`/activities/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/activities/${id}`).then((r) => r.data),
  roster: (id: number, all = false) => api.get<Roster>(`/activities/${id}/registrations`, { params: all ? { all: 1 } : {} }).then((r) => r.data),
  candidates: (id: number, p: { lesson_id?: number; student_ids?: number[] }) =>
    api.get<{ data: Candidate[] }>(`/activities/${id}/candidates`, { params: p }).then((r) => r.data),
  register: (id: number, d: { student_ids: number[]; charge_book?: boolean }) =>
    api.post<{ message: string; data: { student_id: number; full_name: string; outcome: Outcome; reason: string | null }[] }>(`/activities/${id}/registrations`, d).then((r) => r.data),
  confirm: (id: number, regId: number) => api.post<{ message: string }>(`/activities/${id}/registrations/${regId}/confirm`).then((r) => r.data),
  cancel: (id: number, regId: number) => api.post<{ message: string }>(`/activities/${id}/registrations/${regId}/cancel`).then((r) => r.data),
  attendance: (id: number, date?: string) => api.get<AttendanceSheet>(`/activities/${id}/attendance`, { params: date ? { date } : {} }).then((r) => r.data),
  saveAttendance: (id: number, d: { date: string; rows: { student_id: number; status: ActivityAttendanceStatus | null; notes: string | null }[] }) =>
    api.put<{ message: string }>(`/activities/${id}/attendance`, d).then((r) => r.data),
  attendanceSummary: (id: number) => api.get<{ registered: number; data: (Tally & { date: string })[] }>(`/activities/${id}/attendance/summary`).then((r) => r.data),
  deliverBook: (id: number, d: { student_ids: number[]; charge: boolean; delivered_at?: string }) =>
    api.post<{ message: string; delivered: number }>(`/activities/${id}/book-deliveries`, d).then((r) => r.data),
  undoBook: (id: number, deliveryId: number) => api.delete<{ message: string }>(`/activities/${id}/book-deliveries/${deliveryId}`).then((r) => r.data),
  saveEvaluations: (id: number, rows: { student_id: number; score: number | null; grade: string | null; notes: string | null }[]) =>
    api.put<{ message: string }>(`/activities/${id}/evaluations`, { rows }).then((r) => r.data),
}
