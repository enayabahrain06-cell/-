import { api } from './client'

export interface AuditRow {
  id: number
  action: string
  action_label: string
  subject: string
  subject_id: number
  user: { id: number; name: string } | null
  old_values: Record<string, unknown>
  new_values: Record<string, unknown>
  ip: string | null
  created_at: string
}

export interface AuditFilters { action?: string; user_id?: string; from?: string; to?: string; page?: number }

const clean = (p: object) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== ''))

export const auditApi = {
  list: (f: AuditFilters) => api.get<{ data: AuditRow[]; meta: { current_page: number; last_page: number; total: number } }>('/audit-logs', { params: clean(f) }).then((r) => r.data),
  options: () => api.get<{ data: { groups: { value: string; label: string }[]; actions: string[]; users: { id: number; name: string }[] } }>('/audit-logs/options').then((r) => r.data.data),
}
