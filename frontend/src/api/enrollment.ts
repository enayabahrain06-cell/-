import { api } from './client'

export interface EnrollmentCircle {
  id: number
  name: string
  teacher: string | null
  location: string | null
  days: string[]
  start_time: string
  end_time: string
  free_seats: number
}

export interface EnrollmentPackage {
  id: number
  name: string
  gender: 'male' | 'female' | 'mixed'
  min_age: number
  max_age: number
  price_fils: number
  start_date: string
  age_at_start: number | null
  seats_left: number
  is_full: boolean
  circles: EnrollmentCircle[]
}

export interface EnrollmentOptions {
  data: EnrollmentPackage[]
  can_record_payment: boolean
  memorization_levels: { value: string; label: string }[]
}

export interface LookupChild {
  id: number
  student_no: string
  full_name: string
  gender: 'male' | 'female'
  birth_date: string
}

export interface LookupResult {
  guardian: { id: number; name: string; phone: string; children: LookupChild[]; hidden_children: number } | null
  duplicates: { reason: 'same_name_birth_date' | 'same_name_guardian'; student_no: string | null; full_name: string | null; birth_date: string | null }[]
}

export interface EnrollPayload {
  full_name: string
  birth_date: string
  gender: 'male' | 'female'
  guardian_name: string
  guardian_phone: string
  student_phone?: string
  /** Nine-digit CPR (stored on the student; unique). */
  cpr?: string
  address?: string
  memorization_level: string
  /** Absent with without_package: the student is saved with no package or circle yet. */
  package_id?: number
  without_package?: boolean
  lesson_id?: number | null
  waitlist?: boolean
  record_payment?: boolean
  payment_amount_fils?: number
  confirm_duplicate?: boolean
  photo?: File | null
}

export interface EnrollResult {
  status: 'enrolled' | 'waitlist' | 'saved'
  message: string
  request_no: string | null
  waitlist_position: number | null
  student: { id: number; student_no: string; full_name: string } | null
  payment: { id: number; receipt_no: string; amount_fils: number } | null
}

export interface ImportRow {
  row: number
  data: Record<string, string | number | null>
  errors: Record<string, string>
  warnings: string[]
}

export interface ImportPreview {
  rows: ImportRow[]
  valid: number
  warnings: number
  invalid: number
}

export interface ImportResult {
  enrolled: { row: number; student_no: string; full_name: string }[]
  skipped: ImportRow[]
  message: string
}

function toForm(payload: EnrollPayload): FormData {
  const form = new FormData()
  for (const [key, value] of Object.entries(payload)) {
    if (value === undefined || value === null || value === '') continue
    if (value instanceof File) form.append(key, value)
    else if (typeof value === 'boolean') form.append(key, value ? '1' : '0')
    else form.append(key, String(value))
  }
  return form
}

export const enrollmentApi = {
  options: (params: { birth_date?: string; gender?: string }) => api.get<EnrollmentOptions>('/enrollment/options', { params }).then((r) => r.data),
  lookup: (params: { guardian_phone?: string; full_name?: string; birth_date?: string }) => api.get<LookupResult>('/enrollment/lookup', { params }).then((r) => r.data),
  enroll: (payload: EnrollPayload) => api.post<EnrollResult>('/enrollment', toForm(payload)).then((r) => r.data),
  template: () => api.get<Blob>('/enrollment/import/template', { responseType: 'blob' }).then((r) => r.data),
  preview: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return api.post<ImportPreview>('/enrollment/import/preview', form).then((r) => r.data)
  },
  commit: (rows: ImportRow[], includeWarnings: boolean) =>
    api.post<ImportResult>('/enrollment/import', { rows: rows.map(({ row, data }) => ({ row, data })), include_warnings: includeWarnings }).then((r) => r.data),
}
