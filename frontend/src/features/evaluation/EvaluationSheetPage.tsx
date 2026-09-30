import { useCallback, useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CRITERIA, evaluationsApi, type Criterion, type SavedScore, type Sheet, type Suggestion } from '../../api/evaluations'
import { isAxiosError } from 'axios'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { OrnamentDivider } from '../../components/ornaments'
import { Card, ErrorState, LoadingState, Notice, PrimaryButton, EmptyCard } from '../../components/ui'
import { formatDate, formatNumber, formatPercent, formatTime } from '../../lib/format'
import ScoreGrid, { emptyDraft, isComplete, type Draft } from './ScoreGrid'
import MobileScoreEntry, { MobileEntryEmpty, MobileEntrySkeleton, MobileSetAll, MobileSheetHeader } from './MobileEvaluation'
import { HeaderAction } from '../../components/mobile/MobileChrome'
import MobileToast from '../../components/mobile/Toast'
import CriteriaSheet from './CriteriaSheet'

export default function EvaluationSheetPage() {
  const { sessionId } = useParams()
  const id = Number(sessionId)
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const qc = useQueryClient()
  // ?subject= evaluates another subject of the class (التقييمات); ?division= keeps one division's students (تقييم طلبة التقسيم).
  const [params, setParams] = useSearchParams()
  const subjectId = Number(params.get('subject')) || undefined
  const divisionId = Number(params.get('division')) || undefined
  const q = useQuery({ queryKey: ['evaluation-sheet', id, subjectId ?? null, divisionId ?? null], queryFn: () => evaluationsApi.sheet(id, { subject_id: subjectId, division_id: divisionId }) })
  // The sheet opens on Quran. A subject teacher who does not teach Quran in this class goes to their first subject.
  const refused = q.isError && isAxiosError(q.error) && q.error.response?.status === 403 && !subjectId
  const own = useQuery({ queryKey: ['evaluation-subjects', id], queryFn: () => evaluationsApi.subjects(id), enabled: refused, retry: false })
  useEffect(() => {
    const first = own.data?.[0]
    if (!first) return
    const next = new URLSearchParams(params)
    next.set('subject', String(first.id))
    setParams(next, { replace: true })
  }, [own.data, params, setParams])

  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [saved, setSaved] = useState<Record<number, SavedScore | null>>({})
  const [suggestions, setSuggestions] = useState<Suggestion[]>([])
  const [dirty, setDirty] = useState(false)
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  // Mobile feedback is a 4-second toast with its own state, so the desktop notice keeps its behaviour.
  const [toast, setToast] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)
  const [setAllOpen, setSetAllOpen] = useState(false)
  const clearToast = useCallback(() => setToast(null), [])

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
    })), divisionId),
    onSuccess: (r) => {
      setSaved((s) => ({ ...s, ...Object.fromEntries(r.data.map((e) => [e.student_id, e])) }))
      setDrafts((ds) => Object.fromEntries(Object.entries(ds).map(([k, d]) => [k, { ...d, progress: [] }])))
      setSuggestions(r.suggested_issues)
      setDirty(false)
      setMsg({ tone: 'success', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
      setToast({ tone: 'ok', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
      void qc.invalidateQueries({ queryKey: ['student-profile'] })
    },
    onError: (e) => { setMsg({ tone: 'error', text: parseApiError(e).message }); setToast({ tone: 'error', text: parseApiError(e).message }) },
  })

  if (q.isLoading || (refused && (own.isLoading || (own.data?.length ?? 0) > 0))) return <><MobileSheetHeader /><MobileEntrySkeleton /><div className="hidden lg:block"><LoadingState /></div></>
  if (q.isError || !q.data) return <><MobileSheetHeader /><ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} /></>
  const sheet = q.data
  const complete = Object.values(drafts).filter(isComplete).length
  const pick = (key: 'subject' | 'division', v: string) => { const next = new URLSearchParams(params); if (v) next.set(key, v); else next.delete(key); setParams(next, { replace: true }) }
  const scope = <SheetScope sheet={sheet} onPick={pick} />
  // Quran with only its four criteria keeps the sheet below as it always was; any other subject (or extra criteria) uses the criteria sheet.
  const generic = !!sheet.subject && (!sheet.subject.is_quran || (sheet.criteria ?? []).some((c) => !c.is_system))

  if (generic) {
    return (
      <>
        <div className="space-y-4 lg:hidden">
          <MobileSheetHeader title={sheet.lesson.name} date={sheet.date} meta={[sheet.subject?.name ?? null, formatDate(sheet.date, locale, { weekday: 'long', day: 'numeric', month: 'long' }), sheet.division?.name ?? null]} />
          {scope}
        </div>
        <div className="hidden space-y-5 lg:block">
          <SheetHeader sheet={sheet} />
          {scope}
        </div>
        <div className="mt-4 lg:mt-5"><CriteriaSheet sheet={sheet} sessionId={id} divisionId={divisionId ?? null} /></div>
      </>
    )
  }

  const setAll = (c: Criterion, v: number | null) => {
    setDrafts((ds) => Object.fromEntries(Object.entries(ds).map(([k, d]) => [k, { ...d, [c]: v }])))
    setDirty(true)
  }

  const onChange = (sid: number, patch: Partial<Draft>) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setDirty(true); setMsg(null) }
  const onSuggestionDone = (s: Suggestion) => setSuggestions((all) => all.filter((x) => !(x.student_id === s.student_id && x.criterion === s.criterion)))

  return (
    <>
    <div className="space-y-4 lg:hidden">
      <MobileSheetHeader title={sheet.lesson.name} date={sheet.date}
        meta={[formatDate(sheet.date, locale, { weekday: 'long', day: 'numeric', month: 'long' }), `${formatTime(sheet.start_time, locale)}–${formatTime(sheet.end_time, locale)}`, sheet.location, sheet.lesson.teacher]}
        actions={sheet.data.length > 0 ? <HeaderAction icon="edit" label={t('set_all')} onClick={() => setSetAllOpen(true)} /> : undefined} />
      {scope}
      {suggestions.length > 0 && <p className="rounded-card bg-info/10 px-4 py-3 text-[13px] text-info"><b>{t('suggest.title')}.</b> {t('suggest.body', { n: formatNumber(sheet.threshold, locale) })}</p>}
      {sheet.data.length === 0 ? <MobileEntryEmpty text={t('empty_roster')} /> : (
        <MobileScoreEntry students={sheet.data.map((r) => r.student)} drafts={drafts} saved={saved} suggestions={suggestions} threshold={sheet.threshold} withProgress
          onChange={onChange} onSuggestionDone={onSuggestionDone} onSave={() => save.mutate()} saving={save.isPending} dirty={dirty} />
      )}
      <MobileSetAll open={setAllOpen} onClose={() => setSetAllOpen(false)} onSet={setAll} />
      <MobileToast message={toast?.text ?? null} tone={toast?.tone} onDone={clearToast} />
    </div>
    <div className="hidden space-y-5 pb-24 lg:block">
      <SheetHeader sheet={sheet} />
      {scope}

      {/* One band: progress on the start side, "set a score for everyone" on the end side (stacked below xl, where the four selects would be too narrow). */}
      {sheet.data.length > 0 && (
        <Card aria-labelledby="scored-title" className="grid gap-4 xl:grid-cols-[minmax(14rem,1fr)_minmax(0,34rem)] xl:items-center xl:gap-8">
          <div className="min-w-0">
            <div className="flex items-baseline justify-between gap-3">
              <h2 id="scored-title" className="text-base font-semibold tabular-nums text-ink">{t('scored', { n: formatNumber(complete, locale), total: formatNumber(sheet.data.length, locale) })}</h2>
              <span className="text-sm font-semibold tabular-nums text-brand-700">{formatPercent(Math.round((complete / sheet.data.length) * 100), locale)}</span>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-ink/6" role="progressbar" aria-valuemin={0} aria-valuemax={sheet.data.length} aria-valuenow={complete} aria-labelledby="scored-title">
              <div className="h-full rounded-full bg-brand-600 transition-[width]" style={{ width: `${(complete / sheet.data.length) * 100}%` }} />
            </div>
            <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink/55">
              {(['saved', 'changed', 'empty'] as const).map((s) => (
                <span key={s} className="inline-flex items-center gap-1.5">
                  <span className={`size-2 rounded-full ${s === 'saved' ? 'bg-brand-600' : s === 'changed' ? 'bg-gold-500' : 'bg-ink/20'}`} aria-hidden="true" />
                  {t(`row_state.${s}`)}
                </span>
              ))}
            </p>
          </div>
          <div className="min-w-0 border-t border-ink/6 pt-3 xl:border-s xl:border-t-0 xl:ps-8 xl:pt-0">
            <p className="mb-2 text-sm font-medium text-ink/75">{t('set_all')}</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {CRITERIA.map((c) => (
                <SelectField key={c} label={t(`criteria.${c}`)} value="" onChange={(e) => e.target.value !== '' && setAll(c, Number(e.target.value))}
                  options={[{ value: '', label: t(`criteria.${c}`) }, ...Array.from({ length: 11 }, (_, i) => 10 - i).map((n) => ({ value: String(n), label: formatNumber(n, locale) }))]} hideLabel />
              ))}
            </div>
          </div>
        </Card>
      )}

      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {suggestions.length > 0 && <Notice tone="info"><b>{t('suggest.title')}.</b> {t('suggest.body', { n: formatNumber(sheet.threshold, locale) })}</Notice>}

      {sheet.data.length === 0 ? (
        <EmptyCard icon="students" title={t('empty_roster')} />
      ) : (
        <ScoreGrid students={sheet.data.map((r) => r.student)} drafts={drafts} saved={saved} suggestions={suggestions} threshold={sheet.threshold} withProgress
          onChange={(sid, patch) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setDirty(true); setMsg(null) }}
          onSuggestionDone={(s) => setSuggestions((all) => all.filter((x) => !(x.student_id === s.student_id && x.criterion === s.criterion)))} />
      )}

      {/* Sticky save bar */}
      <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink/10 bg-white/95 px-4 py-3 shadow-lg backdrop-blur sm:px-6 lg:start-[17rem] lg:px-8">
        <div className="mx-auto flex w-full max-w-page flex-wrap items-center gap-x-4 gap-y-1">
          <span className="text-sm tabular-nums text-ink/60">{t('scored', { n: formatNumber(complete, locale), total: formatNumber(sheet.data.length, locale) })}</span>
          {dirty && <span className="inline-flex items-center gap-1.5 text-sm text-gold-700"><span className="size-2 rounded-full bg-gold-500" />{t('unsaved')}</span>}
          <PrimaryButton className="ms-auto min-w-40" loading={save.isPending} disabled={complete === 0} onClick={() => save.mutate()}>
            {save.isPending ? t('saving') : t('save')}
          </PrimaryButton>
        </div>
      </div>
    </div>
    </>
  )
}

/** Back link, class name, date, time, room and teacher of the session. */
function SheetHeader({ sheet }: { sheet: Sheet }) {
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  return (
    <>
      <Link to={`/evaluation?date=${sheet.date}`} className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
        <Icon name="chevron" className="size-4 ltr:rotate-180" />{t('back')}
      </Link>

      <header>
        <p className="text-sm text-ink/55">{t('sheet_title')}</p>
        <h1 dir="auto" className="font-display text-3xl text-ink">{sheet.lesson.name}</h1>
        <OrnamentDivider className="my-2 max-w-60 text-gold-500/70" />
        <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-ink/65">
          <li className="inline-flex items-center gap-1.5">
            <Icon name="attendance" className="size-4 text-ink/45" />
            {formatDate(sheet.date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}
          </li>
          <li className="inline-flex items-center gap-1.5">
            <Icon name="clock" className="size-4 text-ink/45" />
            <span className="tabular-nums">{formatTime(sheet.start_time, locale)}–{formatTime(sheet.end_time, locale)}</span>
          </li>
          {sheet.location && <li className="inline-flex items-center gap-1.5"><Icon name="pin" className="size-4 text-ink/45" /><span dir="auto">{sheet.location}</span></li>}
          {sheet.lesson.teacher && <li className="inline-flex items-center gap-1.5"><Icon name="teachers" className="size-4 text-ink/45" /><span dir="auto">{sheet.lesson.teacher}</span></li>}
          {sheet.subject && !sheet.subject.is_quran && <li className="inline-flex items-center gap-1.5"><Icon name="lessons" className="size-4 text-ink/45" /><span dir="auto">{sheet.subject.name}</span></li>}
          {sheet.division && <li className="inline-flex items-center gap-1.5"><Icon name="students" className="size-4 text-ink/45" /><span dir="auto">{sheet.division.name}</span></li>}
        </ul>
      </header>
    </>
  )
}

/** Subject (when the user may evaluate more than one here) and division of the sheet. Hidden when there is nothing to choose. */
function SheetScope({ sheet, onPick }: { sheet: Sheet; onPick: (key: 'subject' | 'division', v: string) => void }) {
  const { t } = useTranslation('evaluation')
  const subjects = sheet.subjects ?? []
  const divisions = sheet.divisions ?? []
  if (subjects.length <= 1 && divisions.length === 0 && !sheet.division) return null
  return (
    <div className="flex flex-wrap items-end gap-3">
      {subjects.length > 1 && (
        <SelectField label={t('scope.subject')} className="w-full sm:w-56" value={String(sheet.subject?.id ?? '')} onChange={(e) => onPick('subject', e.target.value)}
          options={subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
      )}
      {(divisions.length > 0 || sheet.division) && (
        <SelectField label={t('scope.division')} className="w-full sm:w-56" value={String(sheet.division?.id ?? '')} onChange={(e) => onPick('division', e.target.value)}
          options={[{ value: '', label: t('scope.all_students') }, ...divisions.map((d) => ({ value: String(d.id), label: d.name }))]} />
      )}
    </div>
  )
}
