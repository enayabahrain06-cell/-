import type { AxiosInstance } from 'axios'
import type {
  Certificate, CertificateFilters, CertificateList, CertificateOptions, CertificateTemplate, IssuePayload,
  RecipientCertificates, TemplatePayload, UpdatePayload, VerifiedCertificate,
} from './types'

function clean(f: CertificateFilters) {
  const p: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) if (v !== undefined && v !== '' && v !== null) p[k] = v as string | number
  return p
}

/** Client for the certificates API. `http` is the host's axios instance (base URL, auth header, locale). */
export function createCertificatesApi(http: AxiosInstance) {
  const base = (http.defaults.baseURL ?? '').replace(/\/$/, '')
  /** Absolute API URLs from resources → paths relative to the axios base (so auth and proxy apply). */
  const rel = (url: string) => {
    if (base && url.startsWith(base)) return url.slice(base.length)
    return url.replace(/^.*?\/api(?=\/)/, '')
  }
  const blobUrl = async (url: string, params?: Record<string, string>) => {
    const r = await http.get(rel(url), { responseType: 'blob', params })
    return URL.createObjectURL(r.data as Blob)
  }

  return {
    options: () => http.get<{ data: CertificateOptions }>('/certificates/options').then((r) => r.data.data),
    list: (f: CertificateFilters) => http.get<CertificateList>('/certificates', { params: clean(f) }).then((r) => r.data),
    show: (id: number) => http.get<{ data: Certificate }>(`/certificates/${id}`).then((r) => r.data.data),
    issue: (p: IssuePayload) => http.post<{ message: string; data: Certificate[] }>('/certificates', p).then((r) => r.data),
    update: (id: number, p: UpdatePayload) => http.put<{ message: string; data: Certificate }>(`/certificates/${id}`, p).then((r) => r.data),
    remove: (id: number) => http.delete<{ message: string }>(`/certificates/${id}`).then((r) => r.data),
    approve: (id: number) => http.post<{ message: string; data: Certificate }>(`/certificates/${id}/approve`).then((r) => r.data),
    approveMany: (ids: number[]) => http.post<{ message: string; approved: number }>('/certificates/approve', { ids }).then((r) => r.data),
    revoke: (id: number, reason: string) => http.post<{ message: string; data: Certificate }>(`/certificates/${id}/revoke`, { reason }).then((r) => r.data),
    send: (id: number) => http.post<{ message: string; sent: number }>(`/certificates/${id}/send`).then((r) => r.data),
    /** Inline PDF through the authenticated endpoint, as an object URL (for the preview iframe). */
    pdfObjectUrl: (id: number) => blobUrl(`/certificates/${id}/pdf`),
    forRecipient: (type: string, id: number | string) =>
      http.get<RecipientCertificates>(`/certificates/recipients/${encodeURIComponent(type)}/${encodeURIComponent(String(id))}`).then((r) => r.data),

    templates: () => http.get<{ data: CertificateTemplate[] }>('/certificate-templates').then((r) => r.data.data),
    saveTemplate: (type: string, p: TemplatePayload) =>
      http.put<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}`, p).then((r) => r.data),
    uploadSignature: (type: string, slot: number, file: File) => {
      const form = new FormData()
      form.append('image', file)
      return http.post<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}/signatures/${slot}`, form).then((r) => r.data)
    },
    removeSignature: (type: string, slot: number) =>
      http.delete<{ message: string; data: CertificateTemplate }>(`/certificate-templates/${type}/signatures/${slot}`).then((r) => r.data),
    signatureObjectUrl: (url: string) => blobUrl(url),
    previewObjectUrl: (type: string, locale: string) => blobUrl(`/certificate-templates/${type}/preview`, { locale }),

    verify: (token: string) => http.get<{ data: VerifiedCertificate }>(`/public/certificates/verify/${encodeURIComponent(token)}`).then((r) => r.data.data),
  }
}

export type CertificatesApi = ReturnType<typeof createCertificatesApi>

/** Open an object URL in a new tab and release it later. */
export function openObjectUrl(url: string) {
  window.open(url, '_blank', 'noopener')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}
