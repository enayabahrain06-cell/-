import { api } from './client'

export type Method = 'cash' | 'bank_transfer' | 'benefit' | 'card'
export const METHODS: Method[] = ['cash', 'benefit', 'bank_transfer', 'card']

export interface PaymentRow {
  id: number
  receipt_no: string
  student: { id: number; full_name: string; student_no: string } | null
  amount_fils: number
  method: Method
  method_label: string
  reference: string | null
  note: string | null
  received_by: string | null
  paid_at: string
  receipt_sent_at: string | null
  receipt_pdf_url: string
  receipt_image_url: string | null
  allocations?: { invoice_id: number; invoice_no: string; description: string; amount_fils: number }[]
}

export interface InvoiceRow {
  id: number
  invoice_no: string
  student?: { id: number; full_name: string; student_no: string; guardian_phone: string }
  package?: { id: number; name: string } | null
  description: string
  amount_fils: number
  paid_fils: number
  outstanding_fils: number
  due_date: string
  is_overdue: boolean
  status: 'open' | 'partial' | 'paid' | 'cancelled'
  status_label: string
}

export interface RefundRow {
  id: number
  refund_no: string
  student: { id: number; full_name: string; student_no: string }
  amount_fils: number
  method: Method
  reference: string | null
  note: string | null
  approved_by: string | null
  paid_at: string
}

export interface FinanceReport {
  title: string
  period: string
  filters: { from: string; to: string; package_id: number | null; gender: string | null; track: string }
  summary: [string, string][]
  data: {
    totals: { collected: number; refunded: number; net: number; invoiced: number; payments: number; students_due: number; outstanding: number }
    by_package: { name: string; collected: number; invoiced: number; outstanding: number }[]
    by_method: { method: Method; label: string; count: number; amount: number }[]
    by_month: { period: string; collected: number; refunded: number }[]
    outstanding: { student_id: number; student_no: string; full_name: string; balance_fils: number }[]
  }
}

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

export const paymentsApi = {
  list: (p: Record<string, string | number | undefined>) => api.get<Paged<PaymentRow>>('/payments', { params: p }).then((r) => r.data),
  record: (form: FormData) => api.post<{ message: string; data?: PaymentRow; payment?: PaymentRow }>('/payments', form).then((r) => r.data),
  resendReceipt: (id: number) => api.post(`/payments/${id}/resend-receipt`),
  invoices: (p: Record<string, string | number | boolean | undefined>) => api.get<Paged<InvoiceRow>>('/invoices', { params: p }).then((r) => r.data),
  createInvoice: (d: { student_id: number; amount: string; due_date: string; description: string; term?: string; academic_term_id?: number }) =>
    api.post('/invoices', { ...d, amount_fils: Math.round(Number(d.amount) * 1000) }).then((r) => r.data),
  cancelInvoice: (id: number, note: string) => api.post(`/invoices/${id}/cancel`, { note }),
  refunds: (p: Record<string, string | number | undefined>) => api.get<Paged<RefundRow>>('/refunds', { params: p }).then((r) => r.data),
  refund: (d: { student_id: number; amount: string; method: Method; reference?: string; note: string }) => api.post('/refunds', d).then((r) => r.data),
  adjust: (studentId: number, d: { amount: string; note: string; reference?: string }) => api.post(`/students/${studentId}/wallet/adjust`, d).then((r) => r.data),
  finance: (p: Record<string, string | undefined>) => api.get<FinanceReport>('/reports/finance', { params: p }).then((r) => r.data),
  financeExport: async (p: Record<string, string | undefined>, format: 'xlsx' | 'pdf') => {
    const r = await api.get('/reports/finance', { params: { ...p, format }, responseType: 'blob' })
    return r.data as Blob
  },
  receiptPdf: async (id: number) => (await api.get(`/payments/${id}/receipt.pdf`, { responseType: 'blob' })).data as Blob,
}

/** Save or open a downloaded blob (receipts, exports) with a proper file name. */
export function saveBlob(blob: Blob, filename: string, open = false) {
  const url = URL.createObjectURL(blob)
  if (open) {
    window.open(url, '_blank', 'noopener')
  } else {
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
}
