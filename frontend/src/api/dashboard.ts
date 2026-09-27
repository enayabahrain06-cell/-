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
  subject: { type: string; id: number; student_id?: number } | null
  created_at: string | null
  resolvable: boolean
  /** Hall conflicts: one-line summary plus the full list for the "View sessions" dialog. */
  conflict?: AlertConflict
  /** Repeated absence: current run of absences in a row. */
  absence?: { consecutive: number; has_phone: boolean }
}

export interface AlertConflict {
  location: string | null
  location_id: number | null
  with: string[]
  count: number
  from: string | null
  to: string | null
  weekdays: number[]
  start_time: string
  end_time: string
  text: string
  sessions: { date: string; start_time: string; end_time: string; title: string; kind: string }[]
}

export interface AlertsPage {
  data: DashboardAlert[]
  meta: { current_page: number; last_page: number; total: number; per_page: number; all_total: number; by_type: Record<string, number> }
}

export interface AgeBand {
  key: string
  min: number
  max: number | null
  count: number
}

export interface AgeDistribution {
  total: number
  average: number | null
  bands: AgeBand[]
}

export interface DashboardData {
  date: string
  generated_at: string
  age_distribution: AgeDistribution
  scope: { track: 'male' | 'female' | 'both'; own_circles_only: boolean }
  kpis: DashboardKpis
  today: TodaySession[]
  attendance_chart: AttendanceDay[]
  alerts: { total: number; by_type: Record<string, number>; items: DashboardAlert[] }
}

export const dashboardApi = {
  get: () => api.get<{ data: DashboardData }>('/dashboard').then((r) => r.data.data),
  alerts: (params: { type?: string; page?: number; per_page?: number; term?: string }) =>
    api.get<AlertsPage>('/alerts', { params }).then((r) => r.data),
  resolveAlert: (id: number) =>
    api.post<{ message: string; resolved_by: string; resolved_at: string }>(`/alerts/${id}/resolve`).then((r) => r.data),
  messageGuardian: (id: number) => api.post<{ message: string }>(`/alerts/${id}/message-guardian`).then((r) => r.data),
}
