// Certificates live in the @ahl/certificates-react package (packages/certificates-react); this file keeps
// the student report download that shares its blob helpers.
import { openObjectUrl } from '@ahl/certificates-react'
import { api } from './client'

/** The printable student report (authenticated PDF) as an object URL. */
export const studentReportObjectUrl = async (studentId: number) => {
  const r = await api.get(`/students/${studentId}/report.pdf`, { responseType: 'blob' })
  return URL.createObjectURL(r.data as Blob)
}

export { openObjectUrl }
