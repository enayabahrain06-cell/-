import { api } from './client'

export interface ArchiveRecord {
  id: number
  archive_batch_id: number
  student: { id: number; full_name: string; student_no: string } | null
  cpr: string | null
  student_no: string | null
  full_name: string
  academic_year: string
  term_label: string | null
  level_label: string | null
  class_label: string | null
  subject: string | null
  result: string | null
  grade: string | null
  notes: string | null
}
export interface ArchiveBatch { id: number; file_name: string; rows_count: number; matched_count: number; notes: string | null; uploaded_by: string | null; created_at: string | null }
export interface ArchivePreviewRow {
  row: number
  data: Record<string, string | null>
  errors: Record<string, string>
  match: { id: number; full_name: string; student_no: string; by: 'cpr' | 'student_no' | 'name' } | null
}
export interface ArchivePreview { file_name: string; rows: ArchivePreviewRow[]; valid: number; matched: number; invalid: number }
export interface ArchiveFilters { search?: string; academic_year?: string; level?: string; student_id?: number; page?: number }

export const archiveApi = {
  template: () => api.get<Blob>('/archive/template', { responseType: 'blob' }).then((r) => r.data),
  preview: (file: File) => {
    const form = new FormData()
    form.append('file', file)
    return api.post<ArchivePreview>('/archive/preview', form).then((r) => r.data)
  },
  commit: (d: { file_name: string; notes: string | null; rows: { row: number; data: Record<string, string | null> }[] }) =>
    api.post<{ message: string; data: ArchiveBatch }>('/archive/batches', d).then((r) => r.data),
  batches: () => api.get<{ data: ArchiveBatch[] }>('/archive/batches').then((r) => r.data.data),
  removeBatch: (id: number) => api.delete<{ message: string }>(`/archive/batches/${id}`).then((r) => r.data),
  records: (f: ArchiveFilters) =>
    api.get<{ data: ArchiveRecord[]; meta: { current_page: number; last_page: number; total: number }; years: string[] }>('/archive/records', { params: f }).then((r) => r.data),
  student: (studentId: number) => api.get<{ data: ArchiveRecord[] }>(`/students/${studentId}/archive`).then((r) => r.data.data),
}
