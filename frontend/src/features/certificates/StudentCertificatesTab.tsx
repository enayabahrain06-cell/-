import { RecipientCertificates } from '@ahl/certificates-react'
import { useTranslation } from 'react-i18next'

/**
 * Student profile "Certificates" tab, also used on the family home (the certificates package's recipient list).
 * Staff see drafts and can issue; the student and guardian get a read-only list (download + WhatsApp share).
 */
export default function StudentCertificatesTab({ studentId }: { studentId: number }) {
  const { t } = useTranslation('certificates')
  return (
    <RecipientCertificates type="student" id={studentId}
      summaryTypes={[{ type: 'completion', label: t('student.memorization') }, { type: 'excellence', label: t('student.excellence') }]} />
  )
}
