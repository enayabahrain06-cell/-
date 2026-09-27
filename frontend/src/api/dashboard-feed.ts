import { api } from './client'

export type UpcomingType = 'exam' | 'certificates' | 'messages' | 'package'

export interface UpcomingItem {
  id: string
  type: UpcomingType
  /** YYYY-MM-DD (Asia/Bahrain). Standing piles (certificates, messages) sit on today. */
  date: string
  /** Exams: opening time (ISO, +03:00). */
  at: string | null
  title: string
  subtitle: string | null
  /** Exams: students sitting it; certificates / messages: how many wait. */
  count: number | null
  /** Certificates / messages: date of the oldest one waiting. */
  since?: string | null
  draft?: boolean
  link: string
}

export interface UpcomingData {
  from: string
  to: string
  items: UpcomingItem[]
}

export type ActivityType = 'attendance' | 'evaluation' | 'registration' | 'payment' | 'schedule'

export interface ActivityItem {
  id: string
  type: ActivityType
  /** Localized server-side (Arabic-Indic digits in Arabic). */
  description: string
  user: string | null
  at: string
  link: string | null
  /** Payments only. */
  amount_fils?: number
}

export interface ActivityPage {
  data: ActivityItem[]
  meta: { current_page: number; last_page: number; total: number; per_page: number; window_days: number }
}

export const dashboardFeedApi = {
  upcoming: (params: { term?: string }) => api.get<{ data: UpcomingData }>('/dashboard/upcoming', { params }).then((r) => r.data.data),
  activity: (params: { term?: string }) => api.get<{ data: ActivityItem[] }>('/dashboard/activity', { params }).then((r) => r.data.data),
  activityAll: (params: { term?: string; page?: number; per_page?: number }) =>
    api.get<ActivityPage>('/dashboard/activity/all', { params }).then((r) => r.data),
}
