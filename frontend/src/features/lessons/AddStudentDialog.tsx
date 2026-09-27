import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { lessonsApi, type Lesson, type LessonCandidate } from '../../api/lessons'
import { parseApiError } from '../../api/client'
import Avatar from '../../components/Avatar'
import { Badge, Modal, Notice, SecondaryButton, Segmented, TextInput } from '../../components/ui'
import QuickEnrollForm from '../enrollment/QuickEnrollForm'
import { formatNumber } from '../../lib/format'

type Mode = 'existing' | 'new'

/**
 * "Add student" from the circle page: pick an existing student (every match shows whether it fits the
 * circle and why not), or enroll a new one through the quick-enroll form with this circle locked.
 * The server re-checks gender, age, seats and track on save; a student in another circle is moved, never double-enrolled.
 */
export default function AddStudentDialog({ lesson, canQuickEnroll, onClose, onChanged }: { lesson: Lesson; canQuickEnroll: boolean; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation('lessons')
  const locale = i18n.language
  const [mode, setMode] = useState<Mode>('existing')
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  // Search once the user pauses typing.
  useEffect(() => {
    const id = setTimeout(() => setTerm(search.trim()), 350)
    return () => clearTimeout(id)
  }, [search])

  const candidates = useQuery({
    queryKey: ['lesson-candidates', lesson.id, term],
    queryFn: () => lessonsApi.candidates(lesson.id, term),
    enabled: mode === 'existing' && term.length >= 2,
  })

  const add = useMutation({
    mutationFn: (c: LessonCandidate) => lessonsApi.enroll(lesson.id, [c.id], c.action === 'move'),
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); onChanged(); void candidates.refetch() },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })

  const pick = (c: LessonCandidate) => {
    if (c.action === 'move' && !window.confirm(t('add.move_confirm', { name: c.full_name, from: c.circles.map((x) => x.name).join(locale === 'ar' ? '، ' : ', '), to: lesson.name }))) return
    setNotice(null)
    add.mutate(c)
  }

  const n = (v: number) => formatNumber(v, locale)
  const rows = candidates.data?.data ?? []

  return (
    <Modal title={t('add.title', { name: lesson.name })} onClose={onClose} wide
      footer={<SecondaryButton onClick={onClose}>{t('add.close')}</SecondaryButton>}>
      {canQuickEnroll && (
        <Segmented name="add-student-mode" label={t('add.mode')} value={mode}
          options={[{ value: 'existing', label: t('add.mode_existing') }, { value: 'new', label: t('add.mode_new') }]}
          onChange={(v) => { setMode(v); setNotice(null) }} />
      )}

      {mode === 'existing' ? (
        <>
          {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
          <TextInput label={t('add.search_label')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('add.search_hint')} dir="auto" autoComplete="off" />
          {candidates.data && <p className="text-xs text-ink/55">{candidates.data.free_seats > 0 ? t('add.free_seats', { n: n(candidates.data.free_seats) }) : t('add.reasons.full')}</p>}
          {term.length < 2 ? (
            <p className="text-sm text-ink/55">{t('add.type_more')}</p>
          ) : candidates.isLoading ? (
            <p className="text-sm text-ink/55">{t('add.searching')}</p>
          ) : candidates.isError ? (
            <Notice tone="error">{parseApiError(candidates.error).message}</Notice>
          ) : rows.length === 0 ? (
            <p className="text-sm text-ink/55">{t('add.no_results')}</p>
          ) : (
            <ul className="divide-y divide-ink/6 rounded-xl border border-ink/10" aria-live="polite">
              {rows.map((c) => (
                <li key={c.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
                  <Avatar name={c.full_name} initial={c.initial} src={c.photo_url} gender={c.gender} size="sm" />
                  <span className="min-w-0 flex-1">
                    <span dir="auto" className="block font-medium text-ink">{c.full_name}</span>
                    <span className="block text-xs text-ink/55">
                      <span dir="ltr">{c.student_no}</span>
                      {c.age_at_start !== null && <> · {t('add.age', { n: n(c.age_at_start) })}</>}
                      {c.circles.length > 0 && <> · {t('add.in_circle', { name: c.circles.map((x) => x.name).join(locale === 'ar' ? '، ' : ', ') })}</>}
                    </span>
                  </span>
                  {c.action ? (
                    <SecondaryButton disabled={add.isPending} onClick={() => pick(c)} className={c.action === 'move' ? 'border-gold-500/50 text-gold-700' : ''}>
                      {c.action === 'move' ? t('add.move') : t('add.add')}
                    </SecondaryButton>
                  ) : (
                    <Badge tone={c.reason === 'already_in' ? 'info' : 'muted'}>{t(`add.reasons.${c.reason}`)}</Badge>
                  )}
                </li>
              ))}
            </ul>
          )}
        </>
      ) : (
        <QuickEnrollForm lock={{ packageId: lesson.package_id, lessonId: lesson.id, lessonName: lesson.name }} onEnrolled={() => onChanged()} />
      )}
    </Modal>
  )
}
