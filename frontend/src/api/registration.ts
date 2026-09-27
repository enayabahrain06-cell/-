import { api } from './client'
import type { StudentSummary } from './students'

export type PackageGender = 'male' | 'female' | 'mixed'
export type RequestStatus = 'pending' | 'accepted' | 'waitlist' | 'rejected'

export interface Package {
  id: number
  name: string
  name_ar: string
  name_en: string
  description: string | null
  min_age: number
  max_age: number
  gender: PackageGender
  gender_label: string
  seats: number
  seats_taken: number
  seats_left: number
  is_full: boolean
  pending_count?: number
  waitlist_count?: number
  lessons_count?: number
  price_fils: number
  days: string[]
  start_time: string
  end_time: string
  start_date: string
  end_date: string | null
  term: string | null
  plan_ayahs: number
  memorization_direction: 'forward' | 'backward'
  status: 'draft' | 'open' | 'closed' | 'archived' | string
  status_label: string
  suitability?: { suitable: boolean; reason: 'closed' | 'age' | 'gender' | null; age_at_start: number; is_full: boolean; seats_left: number }
}

export interface PublicSettings {
  authority: { name_ar: string; name_en: string; address_ar: string | null; address_en: string | null; phone: string | null }
  country_code: string
  currency: string
  show_hijri: boolean
  default_locale: 'ar' | 'en'
  registration_open: boolean
  photo_required: boolean
  memorization_levels: { value: string; label: string }[]
}

export interface RegistrationRequest {
  id?: number
  request_no: string
  status: RequestStatus
  status_label: string
  waitlist_position: number | null
  package?: { id: number; name: string; start_date: string | null; price_fils: number }
  full_name: string
  birth_date?: string | null
  age_at_start: number
  gender: 'male' | 'female'
  student_phone?: string | null
  guardian_name?: string
  guardian_phone?: string
  memorization_level: string
  memorization_level_label: string
  locale: 'ar' | 'en'
  has_photo?: boolean
  reason: string | null
  notes?: string | null
  decided_by?: string | null
  decided_at: string | null
  student?: StudentSummary
  created_at: string
}

export interface SubmitResult { message: string; request_no: string; status: RequestStatus; waitlist_position: number | null; track_url: string }

export interface PackageInput {
  name: string
  name_ar?: string | null
  name_en?: string | null
  description?: string | null
  min_age: number
  max_age: number
  gender: PackageGender
  seats: number
  price: string
  days: string[]
  start_time: string
  end_time: string
  start_date: string
  end_date?: string | null
  term?: string | null
  plan_ayahs?: number | null
  memorization_direction: 'forward' | 'backward'
  status: string
}

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number } }

export const publicApi = {
  settings: () => api.get<PublicSettings>('/public/settings').then((r) => r.data),
  packages: (gender: string, birthDate?: string) => api.get<{ data: Package[] }>('/public/packages', { params: { gender, ...(birthDate ? { birth_date: birthDate } : {}) } }).then((r) => r.data.data),
  submit: (form: FormData) => api.post<SubmitResult>('/public/registrations', form).then((r) => r.data),
  track: (requestNo: string, phone: string) => api.get<{ data: RegistrationRequest }>(`/public/registrations/${encodeURIComponent(requestNo)}`, { params: { phone } }).then((r) => r.data.data),
}

export const packagesApi = {
  list: (params: Record<string, string | number | undefined> = {}) => api.get<Paged<Package>>('/packages', { params: { per_page: 100, ...params } }).then((r) => r.data),
  create: (d: PackageInput) => api.post<{ data: Package }>('/packages', d).then((r) => r.data),
  update: (id: number, d: Partial<PackageInput>) => api.put<{ data: Package }>(`/packages/${id}`, d).then((r) => r.data),
  remove: (id: number) => api.delete(`/packages/${id}`),
}

export const requestsApi = {
  list: (params: Record<string, string | number | undefined>) => api.get<Paged<RegistrationRequest>>('/registrations', { params }).then((r) => r.data),
  show: (id: number) => api.get<{ data: RegistrationRequest }>(`/registrations/${id}`).then((r) => r.data.data),
  accept: (id: number, force = false) => api.post(`/registrations/${id}/accept`, { force }).then((r) => r.data),
  waitlist: (id: number, note?: string) => api.post(`/registrations/${id}/waitlist`, { note }).then((r) => r.data),
  reject: (id: number, reason: string) => api.post(`/registrations/${id}/reject`, { reason }).then((r) => r.data),
  bulkAccept: (d: { package_id: number; statuses?: string[]; gender?: string }) =>
    api.post<{ message: string; accepted: { id: number; request_no: string }[]; skipped: { id: number; request_no: string; reason: string }[]; seats_left: number }>('/registrations/bulk-accept', d).then((r) => r.data),
}
