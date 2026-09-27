import { api } from './client'
import type { StudentSummary } from './students'

export interface LotteryRow {
  id: number
  name: string
  gender: 'male' | 'female' | 'mixed' | null
  status: 'draft' | 'run' | 'approved' | 'cancelled'
  status_label: string
  package: { id: number; name: string } | null
  balance_ages: boolean
  keep_siblings: boolean
  balance_levels: boolean
  seed: string | null
  run_count: number
  run_at: string | null
  approved_at: string | null
  pool_count: number
  results_count: number
  teachers_count: number
}

export interface LotteryDetail extends LotteryRow {
  teachers: {
    lottery_teacher_id: number
    teacher: { id: number; name: string }
    lesson: { id: number; name: string }
    capacity: number
    assigned: number
    students: { result_id: number; student: StudentSummary; age_at_start: number | null }[]
  }[]
  unassigned: StudentSummary[]
  pool: StudentSummary[]
}

export interface LotteryInput {
  package_id?: number
  name: string
  balance_ages: boolean
  keep_siblings: boolean
  balance_levels: boolean
  teachers: { teacher_id: number; lesson_id: number; capacity: number }[]
}

export const lotteryApi = {
  list: (p: Record<string, string | number | undefined> = {}) => api.get<{ data: LotteryRow[]; meta: { current_page: number; last_page: number; total: number } }>('/lotteries', { params: p }).then((r) => r.data),
  show: (id: number) => api.get<{ data: LotteryDetail }>(`/lotteries/${id}`).then((r) => r.data.data),
  create: (d: LotteryInput) => api.post<{ data: LotteryDetail }>('/lotteries', d).then((r) => r.data.data),
  update: (id: number, d: Partial<LotteryInput>) => api.put<{ data: LotteryDetail }>(`/lotteries/${id}`, d).then((r) => r.data.data),
  syncPool: (id: number) => api.post<{ data: LotteryDetail }>(`/lotteries/${id}/pool/sync`).then((r) => r.data.data),
  run: (id: number, seed?: string) => api.post<{ message: string; run: { run_no: number; seed: string; assigned: number; unassigned: number[]; split_families: string[] }; data: LotteryDetail }>(`/lotteries/${id}/run`, seed ? { seed } : {}).then((r) => r.data),
  move: (id: number, resultId: number, lotteryTeacherId: number) => api.post<{ data: LotteryDetail }>(`/lotteries/${id}/results/${resultId}/move`, { lottery_teacher_id: lotteryTeacherId }).then((r) => r.data.data),
  approve: (id: number, notify: boolean) => api.post<{ message: string; result: { enrolled: number; skipped: number; notified: number }; data: LotteryDetail }>(`/lotteries/${id}/approve`, { notify }).then((r) => r.data),
  cancel: (id: number) => api.post(`/lotteries/${id}/cancel`),
}
