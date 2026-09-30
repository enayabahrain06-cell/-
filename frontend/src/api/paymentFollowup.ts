import { api } from './client'
import type { StudentSummary } from './students'

export type FeeStatus = 'paid' | 'partial' | 'unpaid' | 'none'
export interface FollowupRow {
  student: StudentSummary
  lesson: { id: number; name: string }
  level: { id: number; name: string } | null
  invoices: { id: number; invoice_no: string; description: string; amount_fils: number; paid_fils: number; due_date: string | null; status: string }[]
  total_fils: number
  paid_fils: number
  remaining_fils: number
  status: FeeStatus
  overdue: boolean
}
export interface FollowupData {
  term: { id: number; name: string }
  data: FollowupRow[]
  totals: { students: number; total_fils: number; paid_fils: number; remaining_fils: number; by_status: Record<FeeStatus, number> }
  can_record: boolean
  can_remind: boolean
}

export const paymentFollowupApi = {
  list: (p: { lesson_id?: number; level_id?: number; status?: FeeStatus; search?: string }) => api.get<FollowupData>('/payment-followup', { params: p }).then((r) => r.data),
}
