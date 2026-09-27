import { api } from './client'
import type { Paginated } from './students'

export const MESSAGE_STATUSES = ['scheduled', 'queued', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'skipped', 'suppressed'] as const
export type MessageStatus = (typeof MESSAGE_STATUSES)[number]

export interface MessageLog {
  id: number
  recipient_phone: string
  recipient_type: 'guardian' | 'student' | 'user' | string
  student_id: number | null
  student_name?: string | null
  user_id: number | null
  user_name?: string | null
  type: string
  type_label: string | null
  template_key: string | null
  locale: string
  body: string
  status: MessageStatus
  status_label: string | null
  provider: string | null
  provider_message_id: string | null
  attempts: number
  error: string | null
  scheduled_for: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  created_at: string | null
}

export interface LogFilters {
  status?: string
  type?: string
  phone?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}

export interface MessageStats {
  total: number
  by_status: Record<string, number>
  by_type: Record<string, number>
}

export interface MessageTemplate {
  id: number
  key: string
  name_ar: string
  name_en: string
  name: string
  body_ar: string
  body_en: string
  variables: string[]
  is_active: boolean
  updated_at: string | null
}

export interface SendPayload {
  student_ids?: number[]
  phones?: string[]
  to?: 'guardian' | 'student' | 'both'
  body: string
  locale?: 'ar' | 'en'
}

export interface WhatsAppStatus {
  provider: string
  connected: boolean
  detail?: string
}

const clean = <T extends object>(p: T) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

export const messagesApi = {
  logs: (f: LogFilters) => api.get<Paginated<MessageLog>>('/messages/logs', { params: clean(f) }).then((r) => r.data),
  log: (id: number) => api.get<{ data: MessageLog }>(`/messages/logs/${id}`).then((r) => r.data.data),
  resend: (id: number) => api.post<{ data: MessageLog }>(`/messages/logs/${id}/resend`).then((r) => r.data.data),
  resendFailed: (range: { from?: string; to?: string }) =>
    api.post<{ message: string; count: number }>('/messages/resend-failed', clean(range)).then((r) => r.data),
  stats: (range: { from?: string; to?: string }) => api.get<MessageStats>('/messages/stats', { params: clean(range) }).then((r) => r.data),
  send: (p: SendPayload) => api.post<{ message: string; count: number; log_ids: number[] }>('/messages/send', p).then((r) => r.data),
  templates: () => api.get<{ data: MessageTemplate[] }>('/messages/templates').then((r) => r.data.data),
  updateTemplate: (id: number, p: Partial<Pick<MessageTemplate, 'name_ar' | 'name_en' | 'body_ar' | 'body_en' | 'is_active'>>) =>
    api.put<{ data: MessageTemplate }>(`/messages/templates/${id}`, p).then((r) => r.data.data),
  preview: (id: number, locale: 'ar' | 'en', body?: string) =>
    api.get<{ locale: string; body: string }>(`/messages/templates/${id}/preview`, { params: clean({ locale, body }) }).then((r) => r.data),
  whatsappStatus: () => api.get<WhatsAppStatus>('/whatsapp/status').then((r) => r.data),
  /** null when the provider has no QR to show (HTTP 204). */
  whatsappQr: () => api.get<{ qr: string } | ''>('/whatsapp/qr').then((r) => (r.status === 204 || !r.data ? null : r.data.qr)),
}

// --- automatic attendance messaging (section 23) -----------------------------------------------

export interface MessagingRules {
  reminder_1_enabled: boolean
  reminder_1_minutes: number
  reminder_2_enabled: boolean
  reminder_2_minutes: number
  absence_enabled: boolean
  repeated_absence_enabled: boolean
  repeated_absence_throttle_days: number
  repeated_absence_count: number
  repeated_absence_days: number
  location_change_enabled: boolean
  session_cancelled_enabled: boolean
  quiet_start: string
  quiet_end: string
  quiet_days: string[]
  student_copy_min_age: number
  supervisor_phone: string
  auto_reply_hours: number
  invalid_after_failures: number
}

export interface InboxRow {
  id: number
  from_phone: string
  body: string | null
  intent: string
  status: 'processed' | 'open' | 'resolved'
  received_at: string
  student: { id: number; full_name: string } | null
  sender: string | null
  handled_by: string | null
  handled_at: string | null
}

export interface ExcuseRow {
  id: number
  status: 'applied' | 'pending' | 'approved' | 'rejected'
  body: string | null
  phone: string | null
  source: string
  created_at: string
  reviewed_at: string | null
  student: { id: number; full_name: string } | null
  session: { id: number; date: string; lesson: string | null } | null
}

export interface SessionDelivery {
  session: { id: number; date: string; start_time: string; status: string; lesson: { id: number; name: string }; reminder_sent_at: string | null }
  lesson_rule: { reminders_enabled: boolean; second_reminder_enabled: boolean }
  can_send: boolean
  messages: { id: number; type: string; status: string; recipient_phone: string; recipient_type: string | null; student: { id: number; full_name: string } | null; scheduled_for: string | null; sent_at: string | null; delivered_at: string | null; read_at: string | null; error: string | null; body: string }[]
  confirmations: { student: string | null; confirmed_at: string; via: string }[]
  excuses: ExcuseRow[]
}

export const attendanceMessagingApi = {
  rules: () => api.get<{ data: MessagingRules }>('/messages/rules').then((r) => r.data.data),
  saveRules: (d: Partial<MessagingRules>) => api.put<{ message: string; data: MessagingRules }>('/messages/rules', d).then((r) => r.data),
  lessonRule: (lessonId: number, d: { reminders_enabled: boolean; second_reminder_enabled: boolean }) => api.put(`/lessons/${lessonId}/messaging-rule`, d),
  session: (id: number) => api.get<{ data: SessionDelivery }>(`/sessions/${id}/messages`).then((r) => r.data.data),
  sendNow: (id: number) => api.post<{ message: string; data: { queued: number; skipped: number } }>(`/sessions/${id}/messages/send-now`).then((r) => r.data),
  inbox: (status = 'open', page = 1) => api.get<{ data: InboxRow[]; meta: { current_page: number; last_page: number; total: number; open: number } }>('/messages/inbox', { params: { status, page } }).then((r) => r.data),
  resolve: (id: number) => api.post(`/messages/inbox/${id}/resolve`),
  excuses: (status = 'pending') => api.get<{ data: ExcuseRow[] }>('/attendance/excuses', { params: { status } }).then((r) => r.data.data),
  reviewExcuse: (id: number, decision: 'approve' | 'reject') => api.post<{ data: ExcuseRow }>(`/attendance/excuses/${id}/review`, { decision }).then((r) => r.data.data),
}
