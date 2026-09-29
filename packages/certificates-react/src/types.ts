/** Shapes of the ahl/laravel-certificates JSON API. */

export type CertificateStatus = 'draft' | 'approved' | 'revoked'
export type OrnamentLevel = 'full' | 'minimal' | 'off'

export interface Option<T extends string = string> {
  value: T
  label: string
}

export interface CertificateOptions {
  types: Option[]
  statuses: Option<CertificateStatus>[]
  grades: Option[]
  sources: Option[]
  recipient_types: string[]
  context_types: string[]
  /** Languages every template is written in (certificates.locales). */
  locales: string[]
  /** Tokens a template body may use: {name}, {achievement}, … */
  placeholders: string[]
  require_approval: boolean
  /** False when the host has no notifier: the "send" action is hidden. */
  can_send: boolean
}

/** Recipient card from the host (id, type and name always; anything else the host adds). */
export interface Recipient {
  id: number | string
  type: string
  name: string
  [key: string]: unknown
}

export interface CertificateContext {
  id: number | string
  type: string
  name: string
  [key: string]: unknown
}

export interface Certificate {
  id: number
  certificate_no: string
  type: string
  type_label: string
  status: CertificateStatus
  status_label: string
  title: string
  achievement: string
  grade: string | null
  grade_label: string | null
  source: string
  source_label: string
  source_id: number | null
  recipient_type: string | null
  recipient_id: number | string
  recipient?: Recipient | null
  context?: CertificateContext | null
  issued_on: string | null
  /** A second calendar from the host (e.g. Hijri), or null. */
  issued_on_secondary: string | null
  issued_by?: string | null
  approved_at: string | null
  approved_by?: string | null
  revoked_at: string | null
  revoke_reason?: string | null
  sent_at: string | null
  print_count: number
  verify_url: string | null
  /** Authenticated inline PDF (needs the session: fetch as a blob). */
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

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface CertificateFilters {
  status?: CertificateStatus | ''
  type?: string
  source?: string
  recipient_type?: string
  recipient_id?: number | string
  context_type?: string
  context_id?: number | string
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
  recipient_type?: string
  recipient_ids: (number | string)[]
  type: string
  achievement: string
  title?: string | null
  grade?: string | null
  context_type?: string | null
  context_id?: number | string | null
}

export type UpdatePayload = Partial<{ achievement: string; title: string | null; grade: string | null; context_type: string | null; context_id: number | string | null; issued_on: string }>

export interface RecipientCertificates {
  data: Certificate[]
  recipient: Recipient
  summary: {
    total: number
    by_type: Record<string, number>
    drafts: number | null
    latest: { id: number; title: string; achievement: string; issued_on: string | null } | null
  }
  meta: { read_only: boolean; can_issue: boolean }
}

export interface CertificateTemplate {
  type: string
  type_label: string
  /** Per locale: { ar: '…', en: '…' }. */
  title: Record<string, string>
  body: Record<string, string>
  signature1_name: string | null
  signature1_title: string | null
  signature2_name: string | null
  signature2_title: string | null
  ornament_level: OrnamentLevel
  show_photo: boolean
  /** Authenticated image URLs (fetch as blobs). */
  signatures: Record<string, string | null>
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
  recipient_name: string | null
  issued_on: string | null
  issued_on_secondary: string | null
  revoked_at: string | null
  issuer: string
}
