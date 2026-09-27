import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { myExamsApi, type MyExam } from '../../api/exams'
import { api } from '../../api/client'
import type { StudentSummary } from '../../api/students'
import { useAuth } from '../../app/AuthContext'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { Badge, buttonClass, LoadingState, SURFACE, EmptyCard } from '../../components/ui'
import FamilyLayout from '../../layouts/FamilyLayout'
import { formatDate, formatNumber } from '../../lib/format'

/** Student or guardian: exams grouped as open / upcoming / finished. Guardians pick which child. */
export default function MyExamsPage() {
  const { t, i18n } = useTranslation('exams')
  const locale = i18n.language
  const { hasRole } = useAuth()
  const guardian = hasRole('guardian') && !hasRole('student')
  const children = useQuery({ queryKey: ['me-students'], queryFn: () => api.get<{ data: StudentSummary[] }>('/me/students').then((r) => r.data.data), enabled: guardian })
  const [childId, setChildId] = useState<number | undefined>(undefined)
  const studentId = guardian ? childId ?? children.data?.[0]?.id : undefined
  const q = useQuery({ queryKey: ['my-exams', studentId], queryFn: () => myExamsApi.list(studentId), enabled: !guardian || !!studentId })
  const dt = (iso: string) => formatDate(iso, locale, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })

  const card = (e: MyExam, kind: 'open' | 'upcoming' | 'finished') => (
    <li key={e.id} className={`${SURFACE} p-4`}>
      <div className="flex items-start justify-between gap-2">
        <p dir="auto" className="font-semibold text-ink">{e.name}</p>
        <Badge>{t(`type.${e.type}`)}</Badge>
      </div>
      <p className="mt-1 text-sm text-ink/60">
        {e.type === 'paper' ? t('my.paper_note') : kind === 'upcoming' ? t('my.opens', { date: dt(e.opens_at) }) : t('my.closes', { date: dt(e.closes_at) })}
      </p>
      <div className="mt-3 flex flex-wrap items-center gap-2">
        {kind === 'open' && e.type === 'online' && (
          <Link to={`/my/exams/${e.id}${studentId ? `?student=${studentId}` : ''}`} className={buttonClass('primary', 'shrink-0')}>
            {e.attempt ? t('my.resume') : t('my.start')}
          </Link>
        )}
        {kind === 'finished' && e.attempt && (
          e.attempt.total_score !== null && e.attempt.graded_at
            ? <Badge tone={e.attempt.passed ? 'brand' : 'danger'}>{t('my.result', { score: formatNumber(e.attempt.total_score, locale), total: formatNumber(e.total_marks, locale) })}</Badge>
            : <Badge tone="gold">{t('my.awaiting')}</Badge>
        )}
      </div>
    </li>
  )

  return (
    <FamilyLayout>
      <div className="space-y-5">
        <PageBand title={t('my.title')} />
        {guardian && (children.data?.length ?? 0) > 1 && (
          <SelectField className="w-64" label={t('my.student')} value={studentId ?? ''} onChange={(e) => setChildId(Number(e.target.value))}
            options={(children.data ?? []).map((c) => ({ value: String(c.id), label: c.full_name }))} />
        )}
        {q.isLoading ? <LoadingState /> : !q.data ? null : (['open', 'upcoming', 'finished'] as const).map((k) => (
          <section key={k} aria-labelledby={`my-${k}`} className="space-y-3">
            <h2 id={`my-${k}`} className="text-lg font-semibold text-ink">{t(`my.${k}`)}</h2>
            {q.data[k].length === 0 ? <EmptyCard size="sm" icon="exams" title={t('my.none')} /> : <ul className="grid gap-3 *:min-w-0 sm:grid-cols-2">{q.data[k].map((e) => card(e, k))}</ul>}
          </section>
        ))}
      </div>
    </FamilyLayout>
  )
}
