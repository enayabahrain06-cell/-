import { useTranslation } from 'react-i18next'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import Avatar from '../../components/Avatar'
import StudentCertificatesTab from './StudentCertificatesTab'

/**
 * Family home: each child's certificates (a student sees their own), read-only with download and WhatsApp share.
 * `student` and `children` come with the auth payload (/auth/me).
 */
export default function FamilyCertificates() {
  const { t } = useTranslation('certificates')
  const { user } = useAuth()
  const u = user as (typeof user & { student?: StudentSummary | null; children?: StudentSummary[] }) | null
  const list = [...(u?.student ? [u.student] : []), ...(u?.children ?? [])].filter((s, i, a) => a.findIndex((x) => x.id === s.id) === i)
  if (list.length === 0) return null

  return (
    <div className="space-y-5">
      {list.map((s) => (
        <section key={s.id} aria-labelledby={`certs-${s.id}`} className="space-y-3">
          <h2 id={`certs-${s.id}`} className="flex items-center gap-2 text-lg font-semibold text-ink">
            <Avatar name={s.full_name} initial={s.initial} src={s.photo_url} gender={s.gender} size="sm" />
            <span dir="auto">{list.length > 1 || u?.student?.id !== s.id ? t('student.child_certificates', { name: s.full_name }) : t('student.title')}</span>
          </h2>
          <StudentCertificatesTab studentId={s.id} student={s} />
        </section>
      ))}
    </div>
  )
}
