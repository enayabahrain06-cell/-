import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CRITERIA, evaluationsApi, type Criterion, type SavedScore, type Suggestion } from '../../api/evaluations'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { EmptyState, OrnamentDivider } from '../../components/ornaments'
import { ErrorState, LoadingState, Notice, PrimaryButton, SURFACE } from '../../components/ui'
import { formatDate, formatNumber, formatTime } from '../../lib/format'
import ScoreGrid, { emptyDraft, isComplete, type Draft } from './ScoreGrid'

export default function EvaluationSheetPage() {
  const { sessionId } = useParams()
  const id = Number(sessionId)
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['evaluation-sheet', id], queryFn: () => evaluationsApi.sheet(id) })

  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [saved, setSaved] = useState<Record<number, SavedScore | null>>({})
  const [suggestions, setSuggestions] = useState<Suggestion[]>([])
  const [dirty, setDirty] = useState(false)
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  useEffect(() => {
    if (!q.data) return
    setDrafts(Object.fromEntries(q.data.data.map((r) => [r.student.id, emptyDraft(r.evaluation)])))
    setSaved(Object.fromEntries(q.data.data.map((r) => [r.student.id, r.evaluation])))
    setDirty(false)
  }, [q.data])

  const save = useMutation({
    mutationFn: () => evaluationsApi.saveDaily(id, Object.entries(drafts).filter(([, d]) => isComplete(d)).map(([sid, d]) => ({
      student_id: Number(sid),
      memorization: d.memorization as number, tajweed: d.tajweed as number, revision: d.revision as number, behavior: d.behavior as number,
      note: d.note || null,
      ...(d.progress.length ? { progress: d.progress } : {}),
    }))),
    onSuccess: (r) => {
      setSaved((s) => ({ ...s, ...Object.fromEntries(r.data.map((e) => [e.student_id, e])) }))
      setDrafts((ds) => Object.fromEntries(Object.entries(ds).map(([k, d]) => [k, { ...d, progress: [] }])))
      setSuggestions(r.suggested_issues)
      setDirty(false)
      setMsg({ tone: 'success', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
      void qc.invalidateQueries({ queryKey: ['student-profile'] })
    },
    onError: (e) => setMsg({ tone: 'error', text: parseApiError(e).message }),
  })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
  const sheet = q.data
  const complete = Object.values(drafts).filter(isComplete).length

  const setAll = (c: Criterion, v: number | null) => {
    setDrafts((ds) => Object.fromEntries(Object.entries(ds).map(([k, d]) => [k, { ...d, [c]: v }])))
    setDirty(true)
  }

  return (
    <div className="space-y-5 pb-24">
      <Link to={`/evaluation?date=${sheet.date}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <Icon name="chevron" className="size-4 ltr:rotate-180" />{t('back')}
      </Link>
      <header className={`${SURFACE} p-4 sm:p-5`}>
        <p className="text-sm text-ink/55">{t('sheet_title')}</p>
        <h1 dir="auto" className="font-display text-3xl text-ink">{sheet.lesson.name}</h1>
        <OrnamentDivider className="my-2 text-gold-500/70" />
        <p className="text-sm text-ink/65">
          {formatDate(sheet.date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} · {formatTime(sheet.start_time, locale)}–{formatTime(sheet.end_time, locale)}
          {sheet.location && <> · <span dir="auto">{sheet.location}</span></>}
          {sheet.lesson.teacher && <> · <span dir="auto">{sheet.lesson.teacher}</span></>}
        </p>
        {sheet.data.length > 0 && (
          <div className="mt-3 flex flex-wrap items-end gap-2">
            <span className="w-full text-xs text-ink/50">{t('set_all')}</span>
            {CRITERIA.map((c) => (
              <SelectField key={c} label={t(`criteria.${c}`)} className="w-40" value="" onChange={(e) => e.target.value !== '' && setAll(c, Number(e.target.value))}
                options={[{ value: '', label: t(`criteria.${c}`) }, ...Array.from({ length: 11 }, (_, i) => 10 - i).map((n) => ({ value: String(n), label: formatNumber(n, locale) }))]} hideLabel />
            ))}
          </div>
        )}
      </header>

      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {suggestions.length > 0 && <Notice tone="info"><b>{t('suggest.title')}.</b> {t('suggest.body', { n: formatNumber(sheet.threshold, locale) })}</Notice>}

      {sheet.data.length === 0 ? (
        <div className={SURFACE}><EmptyState icon="students" title={t('empty_roster')} /></div>
      ) : (
        <ScoreGrid students={sheet.data.map((r) => r.student)} drafts={drafts} saved={saved} suggestions={suggestions} threshold={sheet.threshold} withProgress
          onChange={(sid, patch) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setDirty(true); setMsg(null) }}
          onSuggestionDone={(s) => setSuggestions((all) => all.filter((x) => !(x.student_id === s.student_id && x.criterion === s.criterion)))} />
      )}

      <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink/8 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 lg:start-[17rem] lg:px-8">
        <div className="flex items-center gap-3">
          {dirty && <span className="text-sm text-gold-700">{t('unsaved')}</span>}
          <PrimaryButton className="ms-auto min-w-40" loading={save.isPending} disabled={complete === 0} onClick={() => save.mutate()}>
            {save.isPending ? t('saving') : t('save')}
          </PrimaryButton>
        </div>
      </div>
    </div>
  )
}
