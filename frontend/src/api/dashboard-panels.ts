import { api } from './client'

/** Filters every dashboard section follows (see features/dashboard/useDashboardFilters). */
export interface PanelFilters {
  term?: string
}

export interface BehindPlanStudent {
  student_id: number
  name: string
  lesson_id: number
  lesson: string | null
  planned_ayahs: number
  actual_ayahs: number
  gap_ayahs: number
  /** Approximate: ayahs ÷ (6236 ÷ 604). */
  gap_pages: number
  /** Share of the full plan target memorized so far (0–100). */
  plan_percent: number
  target_ayahs: number
}

export interface MemorizationPanelData {
  students: number
  pages_this_week: number
  ayahs_this_week: number
  reviews_due: number
  juz_finished_this_month: number
  behind_total: number
  behind: BehindPlanStudent[]
  week_start: string
}

export interface OverdueStudent {
  student_id: number
  name: string
  days_overdue: number
  outstanding_fils: number
  invoices: number
  has_phone: boolean
}

export interface FeesPanelData {
  collected: { this_month_fils: number; last_month_fils: number; change_percent: number | null }
  overdue: { amount_fils: number; students: number; invoices: number }
  due_this_week: { amount_fils: number; invoices: number; students: number }
  top_overdue: OverdueStudent[]
  can_remind: boolean
}

export const dashboardPanelsApi = {
  memorization: (f: PanelFilters) => api.get<{ data: MemorizationPanelData }>('/dashboard/memorization', { params: f }).then((r) => r.data.data),
  fees: (f: PanelFilters) => api.get<{ data: FeesPanelData }>('/dashboard/fees', { params: f }).then((r) => r.data.data),
  remind: (studentId: number) => api.post<{ message: string; sent: number }>(`/dashboard/fees/remind/${studentId}`).then((r) => r.data),
}
