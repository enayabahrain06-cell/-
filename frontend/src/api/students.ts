import { api } from './client'

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page?: number }
}

export interface StudentSummary {
  id: number
  student_no: string
  full_name: string
  initial: string
  gender: 'male' | 'female'
  birth_date: string | null
  memorization_level: string | null
  status: string
  guardian_name: string
  guardian_phone: string
  student_phone: string | null
  locale: 'ar' | 'en'
  has_photo: boolean
  photo_url: string | null
  photo_urls: { profile: string | null; thumb: string | null }
  balance_fils?: number
  is_due?: boolean
  progress: { juz: number | null; surah: number | null; surah_name: string | null; ayah: number | null; memorized_ayahs: number }
  circle?: { id: number; name: string; teacher: string | null } | null
}

export interface StudentFilters {
  search?: string
  gender?: string
  status?: string
  lesson_id?: string
  juz?: string
  due?: boolean
  sort?: string
  page?: number
  per_page?: number
}

export interface Point {
  surah: number
  surah_name: string
  ayah: number
  juz: number
  juz_ordinal: number
  label: string
}

export interface JuzCell {
  juz: number
  ordinal: number
  total: number
  memorized: number
  percent: number
  status: 'memorized' | 'in_progress' | 'not_started'
}

export interface LedgerRow {
  id: number
  type: 'memorized' | 'revised'
  type_label: string
  surah_number: number
  surah_name: string
  from_ayah: number
  to_ayah: number
  ayah_count: number
  juz: number
  recorded_on: string
  note: string | null
}

export interface ScoreRow {
  id: number
  type: 'daily' | 'monthly'
  date: string
  period: string | null
  memorization: number
  tajweed: number
  revision: number
  behavior: number
  total: number
  note: string | null
  teacher: string | null
}

export interface Averages {
  memorization: number | null
  tajweed: number | null
  revision: number | null
  behavior: number | null
  total: number | null
}

export interface Issue {
  id: number
  category: string
  category_label: string
  subcategory: string | null
  subcategory_label: string | null
  description: string
  action_plan: string | null
  severity: 'low' | 'medium' | 'high'
  severity_label: string
  status: 'open' | 'improving' | 'resolved'
  status_label: string
  opened_by: string | null
  opened_at: string | null
  resolved_at: string | null
  next_follow_up_date: string | null
  notes: { id: number; note: string; noted_on: string; added_by: string | null }[]
}

export interface StudentProfile {
  header: {
    id: number
    student_no: string
    full_name: string
    initial: string
    photo_url: string | null
    gender: 'male' | 'female'
    age: number | null
    package: { id: number; name: string } | null
    lesson: { id: number; name: string } | null
    teacher: string | null
    position: { direction: 'forward' | 'backward'; current: Point | null; next: Point | null }
    attendance_percent: number | null
    balance_fils: number
    is_due: boolean
    open_issues: { total: number; by_severity: { low: number; medium: number; high: number } }
  }
  progress: {
    memorized_ayahs: number
    quran_percent: number
    completed_juz: number
    juz_map: JuzCell[]
    plan: { target_ayahs: number; period_start: string; memorized_in_period: number; percent: number | null }
    revised_ayahs_30d: number
    recent: LedgerRow[]
  }
  evaluation: {
    latest_daily: ScoreRow[]
    monthly_averages: ({ period: string; count: number; monthly_evaluation: ScoreRow | null } & Averages)[]
    trend: ({ week_start: string; count: number } & Averages)[]
    rank: { lesson_id: number; rank: number | null; of: number; average: number | null } | null
    latest_note: { note: string; date: string; teacher: string | null } | null
  }
  issues: Issue[]
}

export interface StudentDetail extends StudentSummary {
  age: number | null
  memorization_level_label: string | null
  status_label: string | null
  yearly_target_ayahs: number | null
  notes?: string | null
  guardian: { id: number; name: string; phone: string; locale: string | null } | null
  lessons: { id: number; name: string; status: string; teacher: string | null; package: string | null; location: string | null; days: string[]; start_time: string; end_time: string }[]
}

export interface AttendanceHistory {
  totals: { present: number; late: number; absent: number; excused: number; percent: number | null }
  data: { id: number; date: string; lesson: string | null; status: 'present' | 'late' | 'absent' | 'excused'; memorization_assignment: string | null; revision_assignment: string | null; note: string | null }[]
  meta: Paginated<unknown>['meta']
}

export interface WalletView {
  balance_fils: number
  is_due: boolean
  outstanding_fils: number
  invoices: { id: number; invoice_no: string; description: string; amount_fils: number; paid_fils: number; outstanding_fils: number; due_date: string; is_overdue: boolean; status: string; status_label: string }[]
  transactions: { data: { id: number; type: string; type_label: string; amount_fils: number; balance_after_fils: number; reference: string | null; invoice_no?: string | null; payment_method?: string | null; note: string | null; created_by?: string | null; created_at: string }[] }
}

function params(f: StudentFilters) {
  const p: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === '' || v === false) continue
    p[k] = v === true ? 1 : (v as string | number)
  }
  return p
}

export const studentsApi = {
  list: (f: StudentFilters) => api.get<Paginated<StudentSummary>>('/students', { params: params(f) }).then((r) => r.data),
  show: (id: number) => api.get<{ data: StudentDetail }>(`/students/${id}`).then((r) => r.data.data),
  profile: (id: number) => api.get<{ data: StudentProfile; meta: { read_only: boolean } }>(`/students/${id}/profile`).then((r) => r.data),
  attendance: (id: number, page = 1, status?: string) =>
    api.get<AttendanceHistory>(`/students/${id}/attendance`, { params: { page, ...(status ? { status } : {}) } }).then((r) => r.data),
  wallet: (id: number) => api.get<WalletView>(`/students/${id}/wallet`).then((r) => r.data),
  update: (id: number, data: Partial<Pick<StudentDetail, 'full_name' | 'birth_date' | 'guardian_name' | 'memorization_level' | 'status' | 'yearly_target_ayahs' | 'notes'>>) =>
    api.put<{ data: StudentDetail }>(`/students/${id}`, data).then((r) => r.data.data),
  uploadPhoto: (id: number, file: File) => {
    const form = new FormData()
    form.append('photo', file)
    return api.post(`/students/${id}/photo`, form)
  },
  removePhoto: (id: number) => api.delete(`/students/${id}/photo`),
}

export const lessonsApi = {
  options: () => api.get<{ data: { id: number; name: string; gender: string | null }[] }>('/lessons', { params: { per_page: 200 } }).then((r) => r.data.data),
}
