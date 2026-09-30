import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { distributionApi } from '../../api/distribution'
import type { StudentSummary } from '../../api/students'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import StudentPicker from '../../components/StudentPicker'
import { Badge, ErrorState, LoadingState, Notice, PrimaryButton, SURFACE, TABLE_HEAD, TableWrap, TextArea } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { OptionsError, useClassOptions, useDistOptions } from './shared'

/** تحديث المستوى: move one student to a class of another level this term, with a reason, and show the level history. */
export default function UpdateLevelPage() {
  const { t, i18n } = useTranslation('distribution')
  const qc = useQueryClient()
  const options = useDistOptions()
  const [student, setStudent] = useState<StudentSummary | null>(null)
  const [levelId, setLevelId] = useState<number | ''>('')
  const [lessonId, setLessonId] = useState<number | ''>('')
  const [reason, setReason] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const info = useQuery({ queryKey: ['distribution-level', student?.id], queryFn: () => distributionApi.level(student!.id), enabled: !!student })
  const classOptions = useClassOptions((options.data?.classes ?? []).filter((c) => levelId !== '' && c.level_id === levelId && c.id !== info.data?.current?.lesson_id))

  const save = useMutation({
    mutationFn: () => distributionApi.changeLevel(student!.id, { academic_term_id: options.data!.term.id, level_id: Number(levelId), lesson_id: lessonId === '' ? null : lessonId, reason }),
    onSuccess: (r) => {
      setNotice({ tone: 'success', text: r.message })
      setLevelId(''); setLessonId(''); setReason('')
      void qc.invalidateQueries({ queryKey: ['distribution-level'] })
      void qc.invalidateQueries({ queryKey: ['distribution-options'] })
    },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.update_level')} subtitle={options.data ? t('subtitle', { term: options.data.term.name }) : undefined} />
      </div>
      {options.isLoading ? <LoadingState /> : options.isError ? <OptionsError error={options.error} onRetry={() => void options.refetch()} /> : (
        <div className="grid gap-4 *:min-w-0 lg:grid-cols-2">
          <section className={`${SURFACE} space-y-4 p-4`}>
            <StudentPicker label={t('student')} value={student} onChange={(s) => { setStudent(s); setNotice(null); setLevelId(''); setLessonId('') }} />
            {student && (info.isLoading ? <LoadingState /> : info.isError ? <ErrorState onRetry={() => void info.refetch()} /> : info.data && (
              <>
                <dl className="grid gap-3 rounded-xl bg-page/60 p-3 text-sm sm:grid-cols-2">
                  <div><dt className="text-ink/55">{t('update.current_class')}</dt><dd className="font-medium text-ink">{info.data.current?.lesson ?? t('unplaced')}</dd></div>
                  <div><dt className="text-ink/55">{t('update.current_level')}</dt><dd className="font-medium text-ink">{info.data.current ? info.data.current.level ?? t('no_level') : '—'}</dd></div>
                </dl>
                <div className="grid gap-3 sm:grid-cols-2">
                  <SelectField label={t('update.new_level')} value={String(levelId)} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setLessonId('') }}
                    options={[{ value: '', label: t('choose_level') }, ...(options.data?.levels ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} />
                  <SelectField label={t('class')} value={String(lessonId)} disabled={levelId === ''} onChange={(e) => setLessonId(e.target.value ? Number(e.target.value) : '')} options={classOptions} />
                </div>
                <TextArea label={t('update.reason')} rows={2} dir="auto" value={reason} onChange={(e) => setReason(e.target.value)} />
                <div className="flex justify-end">
                  <PrimaryButton disabled={levelId === '' || !reason.trim()} loading={save.isPending} onClick={() => save.mutate()}>{t('update.save')}</PrimaryButton>
                </div>
              </>
            ))}
            {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          </section>

          <section className="min-w-0 space-y-3">
            <h2 className="font-semibold text-ink">{t('update.history')}</h2>
            {!student ? <Notice tone="info">{t('update.pick_student')}</Notice> : !info.data?.history.length ? (
              <p className="text-sm text-ink/55">{t('update.no_history')}</p>
            ) : (
              <TableWrap surface>
                <table className="w-full min-w-[34rem] text-sm">
                  <thead className={TABLE_HEAD}>
                    <tr>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('update.date')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('promotion.decision')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('update.from')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('update.to')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink/6">
                    {info.data.history.map((h) => (
                      <tr key={h.id}>
                        <td className="px-3 py-2 text-ink/70">{h.created_at ? formatDate(h.created_at, i18n.language, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}</td>
                        <td className="px-3 py-2">
                          <Badge tone={h.decision === 'graduate' ? 'gold' : h.decision === 'level_change' ? 'info' : 'brand'}>{h.decision_label}</Badge>
                          {h.reason && <span dir="auto" className="mt-1 block text-xs text-ink/55">{h.reason}</span>}
                        </td>
                        <td className="px-3 py-2 text-ink/70"><span className="block">{h.from_level ?? '—'}</span><span className="block text-xs text-ink/50">{h.from_lesson ?? ''}</span></td>
                        <td className="px-3 py-2 text-ink/70"><span className="block">{h.to_level ?? '—'}</span><span className="block text-xs text-ink/50">{[h.to_lesson, h.to_term].filter(Boolean).join('، ')}</span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </TableWrap>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
