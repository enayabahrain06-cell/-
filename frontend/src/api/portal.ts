import { api } from './client'
import type { AttendanceStatus } from './attendance'
import type { InvoiceRow, PaymentRow } from './payments'
import type { JuzCell, LedgerRow, Point, StudentSummary } from './students'

/** Student / guardian portal (work plan 3.4). Every endpoint is scoped to the signed-in family on the server. */

export interface PortalCard {
  student: StudentSummary
  circle: { id: number; name: string; teacher: string | null; location: string | null; days: string[]; start_time: string; end_time: string } | null
  today: { session_id: number; date: string; start_time: string; end_time: string; status: 'scheduled' | 'held' | 'cancelled'; attendance: AttendanceStatus | null } | null
  next_session: { date: string; start_time: string; end_time: string; location: string | null } | null
  kpis: { attendance_percent: number | null; evaluation_average: number | null; evaluation_max: number; completed_juz: number; memorized_ayahs: number; quran_percent: number }
  progress: {
    position: { direction: 'forward' | 'backward'; current: Point | null; next: Point | null }
    current_juz: JuzCell | null
    plan: { target_ayahs: number; period_start: string; memorized_in_period: number; percent: number | null }
  }
  latest_note: { note: string; date: string; teacher: string | null } | null
  week: { from: string; attendance: Record<AttendanceStatus, number>; recitations: LedgerRow[] }
  wallet: { balance_fils: number; outstanding_fils: number; is_due: boolean }
  counts: { certificates: number; badges: number }
}

export interface PortalOverview {
  role: 'student' | 'guardian'
  students: PortalCard[]
  totals: { outstanding_fils: number; due_students: number }
}

export interface ScheduleSession {
  id: number
  student_id: number
  student_name: string
  date: string
  start_time: string
  end_time: string
  lesson: string | null
  teacher: string | null
  location: string | null
  location_changed: boolean
  status: 'scheduled' | 'held' | 'cancelled'
  attendance: AttendanceStatus | null
}

export interface PortalSchedule {
  from: string
  to: string
  sessions: ScheduleSession[]
  circles: { student_id: number; id: number; name: string; teacher: string | null; location: string | null; days: string[]; start_time: string; end_time: string }[]
}

export interface PortalMessage {
  id: number
  type: string | null
  body: string
  student: { id: number; full_name: string } | null
  sent_at: string | null
  status: string
}

export interface PortalBadge { id: number; key: string; name: string; description: string | null; icon: string | null; earned: boolean; times: number; last_awarded_at: string | null }

export interface FamilyWallet {
  student: StudentSummary
  balance_fils: number
  is_due: boolean
  outstanding_fils: number
  invoices: InvoiceRow[]
  payments: PaymentRow[]
}

export const portalApi = {
  overview: () => api.get<{ data: PortalOverview }>('/me/overview').then((r) => r.data.data),
  schedule: (studentId?: number) => api.get<{ data: PortalSchedule }>('/me/schedule', { params: { student_id: studentId } }).then((r) => r.data.data),
  messages: (page = 1) => api.get<{ data: PortalMessage[]; meta: { current_page: number; last_page: number; total: number } }>('/me/messages', { params: { page } }).then((r) => r.data),
  badges: (studentId?: number) => api.get<{ data: { student_id: number; badges: PortalBadge[] }[] }>('/me/badges', { params: { student_id: studentId } }).then((r) => r.data.data),
  wallet: () => api.get<{ students: FamilyWallet[] }>('/me/wallet').then((r) => r.data.students),
}
