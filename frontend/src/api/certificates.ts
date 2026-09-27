import { api } from './client'
import type { Paginated, StudentSummary } from './students'

export type CertificateType = 'completion' | 'excellence' | 'competition' | 'exam' | 'attendance' | 'participation'
export type CertificateStatus = 'draft' | 'approved' | 'revoked'
export type CertificateGrade = 'excellent' | 'very_good' | 'good'
export type OrnamentLevel = 'full' | 'minimal' | 'off'

export interface Option<T extends string = string> {
  value: T
  label: string
}

export interface CertificateOptions {
  types: Option<CertificateType>[]
  statuses: Option<CertificateStatus>[]
  grades: Option<CertificateGrade>[]
  sources: Option[]
  require_approval: boolean
}

export interface Certificate {
  id: number
  certificate_no: string
  type: CertificateType
  type_label: string
  status: CertificateStatus
  status_label: string
  title: string
  achievement: string
  grade: CertificateGrade | null
  grade_label: string | null
  source: string
  source_label: string
  student_id: number
  student?: StudentSummary
  exam_id: number | null
  lesson_id: number | null
  lesson?: { id: number; name: string } | null
  issued_on: string | null
  issued_on_hijri: string | null
  issued_by?: string | null
  approved_at: string | null
  approved_by?: string | null
  revoked_at: string | null
  revoke_reason?: string | null
  sent_at: string | null
  print_count: number
  verify_url: string | null
  /** Authenticated inline PDF (needs the bearer token: fetch as a blob). */
  pdf_url: string | null
  /** Signed, short-lived link that downloads the PDF as an attachment (no login needed). */
  download_url: string | null
  /** Signed, short-lived inline link that counts a print (approved only). */
  print_url: string | null
  /** Signed, short-lived inline link for previews (does not count a print). */
  view_url: string | null
  whatsapp_share_url: string | null
  can: { update: boolean; approve: boolean; revoke: boolean; send: boolean } | null
}

export interface CertificateFilters {
  status?: CertificateStatus | ''
  type?: string
  source?: string
  student_id?: number
  lesson_id?: number
  search?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}

export interface CertificateList extends Paginated<Certificate> {
  meta: Paginated<Certificate>['meta'] & { status_counts: Record<CertificateStatus, number> }
}

export interface IssuePayload {
  student_ids: number[]
  type: CertificateType
  achievement: string
  title?: string | null
  grade?: CertificateGrade | null
  lesson_id?: number | null
}

export type UpdatePayload = Partial<{ achievement: string; title: string | null; grade: CertificateGrade | null; lesson_id: number | null; issued_on: string }>

export interface StudentCertificates {
  data: Certificate[]
  summary: {
    total: number
    memorization: number
    excellence: number
    drafts: number | null
    latest: { id: number; title: string; achievement: string; issued_on: string | null } | null
  }
  meta: { read_only: boolean; can_issue: boolean }
}

export interface CertificateTemplate {
  type: CertificateType
  type_label: string
  title_ar: string
  title_en: string
  body_ar: string
  body_en: string
  signature1_name: string | null
  signature1_title: string | null
  signature2_name: string | null
  signature2_title: string | null
  ornament_level: OrnamentLevel
  show_photo: boolean
  /** Authenticated image URLs (fetch as blobs). */
  signatures: { 1: string | null; 2: string | null }
  preview_url: string
  updated_at: string | null
}

export type TemplatePayload = Omit<CertificateTemplate, 'type' | 'type_label' | 'signatures' | 'preview_url' | 'updated_at'>

export interface VerifiedCertificate {
  valid: boolean
  status: 'approved' | 'revoked'
  status_label: string
  certificate_no: string
  type_label: string
  title: string
  achievement: string
  grade_label: string | null
  student_name: string | null
  issued_on: string | null
  issued_on_hijri: string | null
  revoked_at: string | null
  authority: string
}

function clean(f: CertificateFilters) {
  const p: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) if (v !== undefined && v !== '' && v !== null) p[k] = v as string | number
  return p
}

/** Absolute API URLs from resources → paths relative to the axios base (so the bearer token and proxy apply). */
const rel = (url: string) => url.replace(/^.*?\/api(?=\/)/, '')

async function blobUrl(url: string, params?: Record<string, string>) {
  const r = await api.get(rel(url), { responseType: 'blob', params })
  return URL.createObjectURL(r.data as Blob)
}

export const certificatesApi = {
  options: () => api.get<{ data: CertificateOptions }>('/certificates/options').then((r) => r.data.data),
  list: (f: CertificateFilters) => api.get<CertificateList>('/certificates', { params: clean(f) }).then((r) => r.data),
  show: (id: number) => api.get<{ data: Certificate }>(`/certificates/${id}`).then((r) => r.data.data),
  issue: (p: IssuePayload) => api.post<{ message: string; data: Certificate[] }>('/certificates', p).then((r) => r.data),
  update: (id: number, p: UpdatePayload) => api.put<{ message: string; data: Certificate }>(`/certificates/${id}`, p).then((r) => r.data),
  remove: (id: number) => api.delete<{ message: string }>(`/certificates/${id}`).then((r) => r.data),
  approve: (id: number) => api.post<{ message: string; data: Certificate }>(`/certificates/${id}/approve`).then((r) => r.data),
  approveMany: (ids: number[]) => api.post<{ message: string; approved: number }>('/certificates/approve', { ids }).then((r) => r.data),
  revoke: (id: number, reason: string) => api.post<{ message: string; data: Certificate }>(`/certificates/${id}/revoke`, { reason }).then((r) => r.data),
  send: (id: number) => api.post<{ message: string; sent: boolean }>(`/certificates/${id}/send`).then((r) => r.data),
  /** Inline PDF through the authenticated endpoint, as an object URL (for the preview iframe). */
  pdfObjectUrl: (id: number) => blobUrl(`/certificates/${id}/pdf`),
  forStudent: (studentId: number) => api.get<StudentCertificates>(`/students/${studentId}/certificates`).then((r) => r.data),

  templates: () => api.get<{ data: CertificateTemplate[] }>('/certificate-templates').then((r) => r.data.data),
  saveTemplate: (type: CertificateType, p: TemplatePayload) =>
    api.put<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}`, p).then((r) => r.data),
  uploadSignature: (type: CertificateType, slot: 1 | 2, file: File) => {
    const form = new FormData()
    form.append('image', file)
    return api.post<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}/signatures/${slot}`, form).then((r) => r.data)
  },
  removeSignature: (type: CertificateType, slot: 1 | 2) =>
    api.delete<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}/signatures/${slot}`).then((r) => r.data),
  signatureObjectUrl: (url: string) => blobUrl(url),
  previewObjectUrl: (type: CertificateType, locale: 'ar' | 'en') => blobUrl(`/certificate-templates/${type}/preview`, { locale }),

  verify: (token: string) => api.get<{ data: VerifiedCertificate }>(`/public/certificates/verify/${encodeURIComponent(token)}`).then((r) => r.data.data),
}

/** The printable student report (authenticated PDF) as an object URL. */
export const studentReportObjectUrl = (studentId: number) => blobUrl(`/students/${studentId}/report.pdf`)

/** Open an object URL in a new tab and release it later. */
export function openObjectUrl(url: string) {
  window.open(url, '_blank', 'noopener')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}
