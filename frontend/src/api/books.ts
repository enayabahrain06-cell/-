import { api } from './client'

export interface Book {
  id: number
  academic_term_id: number
  title: string
  subject: { id: number; name: string } | null
  level: { id: number; name: string } | null
  price_fils: number
  stock: number | null
  is_active: boolean
  notes: string | null
  deliveries_count: number | null
}
export interface BookInput {
  academic_term_id: number
  title: string
  subject_id: number | null
  level_id: number | null
  price_fils: number
  stock: number | null
  is_active: boolean
  notes: string | null
}
export interface BookOptions {
  subjects: { id: number; name: string }[]
  levels: { id: number; name: string }[]
  classes: { id: number; name: string; level_id: number | null }[]
}
export interface BookFollowupRow {
  student: { id: number; full_name: string; student_no: string; gender: string | null }
  lesson: { id: number; name: string }
  delivery: {
    id: number
    delivered_at: string | null
    delivered_by: string | null
    notes: string | null
    invoice: { id: number; invoice_no: string; amount_fils: number; paid_fils: number; status: string } | null
  } | null
}

export const booksApi = {
  list: () => api.get<{ term: { id: number; name: string }; data: Book[]; options: BookOptions }>('/books').then((r) => r.data),
  create: (d: BookInput) => api.post<{ message: string; data: Book }>('/books', d).then((r) => r.data),
  update: (id: number, d: Partial<BookInput>) => api.put<{ message: string; data: Book }>(`/books/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/books/${id}`).then((r) => r.data),
  followup: (id: number, p: { lesson_id?: number; level_id?: number; delivered?: 'yes' | 'no' }) =>
    api.get<{ book: Book; data: BookFollowupRow[]; totals: { expected: number; delivered: number; all_deliveries: number } }>(`/books/${id}/followup`, { params: p }).then((r) => r.data),
  deliver: (id: number, d: { student_ids: number[]; charge: boolean; delivered_at?: string }) =>
    api.post<{ message: string; delivered: number }>(`/books/${id}/deliveries`, d).then((r) => r.data),
  undo: (id: number, deliveryId: number) => api.delete<{ message: string }>(`/books/${id}/deliveries/${deliveryId}`).then((r) => r.data),
}
