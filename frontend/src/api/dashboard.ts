import { api } from './client'

export interface DashboardKpis {
  active_students: number
  active_circles: number
  sessions_today: number
  attendance_taken_today: number
  attendance_rate_7d: number | null
  pending_registrations?: number
  collected_this_month_fils?: number
  students_due?: number
  outstanding_fils?: number
}

export type LocationStatus = 'ok' | 'changed' | 'conflict' | 'no_hall' | 'cancelled'

export interface TodaySession {
  id: number
  lesson_id: number
  lesson: string
  gender: 'male' | 'female' | null
  teacher: string | null
  start_time: string
  end_time: string
  location: string | null
  location_status: LocationStatus
  session_status: string
  attendance_taken: boolean
  enrolled: number
  present: number
  absent: number
}

export interface AttendanceDay {
  date: string
  present: number
  late: number
  absent: number
  excused: number
  rate: number | null
}

export interface DashboardAlert {
  id: number | null
  kind: 'alert' | 'computed'
  type: string
  type_label: string
  severity: 'danger' | 'warning' | 'info'
  title: string
  body: string | null
  subject: { type: string; id: number } | null
  created_at: string | null
  resolvable: boolean
}

export interface DashboardData {
  date: string
  scope: { track: 'male' | 'female' | 'both'; own_circles_only: boolean }
  kpis: DashboardKpis
  today: TodaySession[]
  attendance_chart: AttendanceDay[]
  alerts: { total: number; by_type: Record<string, number>; items: DashboardAlert[] }
}

export const dashboardApi = {
  get: () => api.get<{ data: DashboardData }>('/dashboard').then((r) => r.data.data),
  resolveAlert: (id: number) => api.post(`/alerts/${id}/resolve`),
}
