import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { StudentSummary } from '../../api/students'
import StudentPicker from '../../components/StudentPicker'
import { PageBand } from '../../components/ornaments'
import { Card } from '../../components/ui'

/**
 * تفاصيل الطالب / معلومات الطالب: find a student, then open their profile — the full history (overview) or the
 * personal information tab (?view=info).
 */
export default function StudentLookupPage() {
  const { t } = useTranslation('students')
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const info = params.get('view') === 'info'
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const title = info ? t('nav:menu.student_info') : t('nav:menu.student_details')

  const pick = (s: StudentSummary | null) => {
    setStudent(s)
    if (s) navigate(`/students/${s.id}${info ? '?tab=details' : ''}`)
  }

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={title} subtitle={info ? t('lookup.info_hint') : t('lookup.details_hint')} />
      </div>
      <Card className="max-w-xl space-y-3">
        <p className="text-sm text-ink/65 lg:hidden">{info ? t('lookup.info_hint') : t('lookup.details_hint')}</p>
        <StudentPicker label={t('lookup.search')} value={student} onChange={pick} />
      </Card>
    </div>
  )
}
