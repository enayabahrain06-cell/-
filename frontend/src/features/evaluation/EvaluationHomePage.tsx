import { useCallback, useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { evaluationsApi, type SavedScore, type Suggestion } from '../../api/evaluations'
import { lessonsApi, studentsApi } from '../../api/students'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { LoadingState, Notice, PrimaryButton, Segmented, TextInput, EmptyCard, FilterBar } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import AttendanceDayPage from '../attendance/AttendanceDayPage'
import ScoreGrid, { emptyDraft, isComplete, type Draft } from './ScoreGrid'
import MobileScoreEntry, { MobileEntryEmpty, MobileEntrySkeleton } from './MobileEvaluation'
import { MSegmented, MSelect } from '../../components/mobile/atoms'
import MobileToast from '../../components/mobile/Toast'

export default function EvaluationHomePage() {
  const { t } = useTranslation('evaluation')
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'monthly' ? 'monthly' : 'daily'
  const setTab = (v: string) => { const n = new URLSearchParams(params); n.set('tab', v); setParams(n, { replace: true }) }

  return (
    <div className="space-y-5">
      <div className="hidden space-y-5 lg:block">
        <PageBand title={t('title')} subtitle={t('subtitle')} />
        <Segmented name="eval-tab" label={t('title')} value={tab} fill
          options={[{ value: 'daily', label: t('tabs.daily') }, { value: 'monthly', label: t('tabs.monthly') }]}
          onChange={setTab} />
      </div>
      {/* Below lg the page header carries the title; the two tabs stay as one segmented control. */}
      <div className="lg:hidden">
        <MSegmented label={t('title')} value={tab} onChange={setTab}
          options={[{ value: 'daily', label: t('tabs.daily') }, { value: 'monthly', label: t('tabs.monthly') }]} />
      </div>
      {tab === 'daily' ? <AttendanceDayPage basePath="/evaluation" embedded /> : <Monthly />}
    </div>
  )
}

function Monthly() {
  const { t, i18n } = useTranslation('evaluation')
  const locale = i18n.language
  const lessons = useQuery({ queryKey: ['lesson-options'], queryFn: lessonsApi.options, staleTime: 5 * 60_000 })
  const [lessonId, setLessonId] = useState<number | null>(null)
  const [period, setPeriod] = useState(() => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bahrain', year: 'numeric', month: '2-digit' }).format(new Date()).slice(0, 7))

  useEffect(() => {
    if (!lessonId && lessons.data?.length) setLessonId(lessons.data[0].id)
  }, [lessons.data, lessonId])

  const roster = useQuery({ queryKey: ['monthly-roster', lessonId], queryFn: () => studentsApi.list({ lesson_id: String(lessonId), per_page: 200 }), enabled: !!lessonId })
  const existing = useQuery({ queryKey: ['monthly-existing', lessonId, period], queryFn: () => evaluationsApi.monthlyList(lessonId as number, period), enabled: !!lessonId })

  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [saved, setSaved] = useState<Record<number, SavedScore | null>>({})
  const [suggestions, setSuggestions] = useState<Suggestion[]>([])
  const [msg, setMsg] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  // Mobile feedback: a 4-second toast with its own state (the desktop notice is unchanged).
  const [toast, setToast] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)
  const clearToast = useCallback(() => setToast(null), [])

  useEffect(() => {
    if (!roster.data || !existing.data) return
    const byStudent = new Map(existing.data.map((e) => [e.student.id, { ...e, student_id: e.student.id }]))
    setDrafts(Object.fromEntries(roster.data.data.map((s) => [s.id, emptyDraft(byStudent.get(s.id))])))
    setSaved(Object.fromEntries(roster.data.data.map((s) => [s.id, byStudent.get(s.id) ?? null])))
    setSuggestions([])
  }, [roster.data, existing.data])

  const save = useMutation({
    mutationFn: () => evaluationsApi.saveMonthly(lessonId as number, period, Object.entries(drafts).filter(([, d]) => isComplete(d)).map(([sid, d]) => ({
      student_id: Number(sid), memorization: d.memorization as number, tajweed: d.tajweed as number, revision: d.revision as number, behavior: d.behavior as number, note: d.note || null,
    }))),
    onSuccess: (r) => {
      setSaved((s) => ({ ...s, ...Object.fromEntries(r.data.map((e) => [e.student_id, e])) }))
      setSuggestions(r.suggested_issues)
      setMsg({ tone: 'success', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
      setToast({ tone: 'ok', text: t('saved', { n: formatNumber(r.data.length, locale) }) })
    },
    onError: (e) => { setMsg({ tone: 'error', text: parseApiError(e).message }); setToast({ tone: 'error', text: parseApiError(e).message }) },
  })

  return (
    <>
    <div className="space-y-4 lg:hidden">
      <div className="grid gap-3 min-[400px]:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <MSelect label={t('monthly.circle')} value={lessonId ? String(lessonId) : ''} onChange={(v) => setLessonId(Number(v))}
          options={(lessons.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))} />
        <label className="block min-w-0">
          <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{t('monthly.period')}</span>
          <input type="month" value={period} max={new Date().toISOString().slice(0, 7)} onChange={(e) => e.target.value && setPeriod(e.target.value)}
            className="h-12 w-full min-w-0 rounded-md border border-ink/10 bg-white px-3 text-[15px] tabular-nums text-ink" />
        </label>
      </div>
      <p className="text-[13px] text-ink/65">{t('monthly.hint')}</p>
      {lessons.isLoading || (lessonId && (roster.isLoading || existing.isLoading)) ? <MobileEntrySkeleton /> : !lessonId ? (
        <MobileEntryEmpty text={t('mobile.circle_empty')} />
      ) : (roster.data?.data.length ?? 0) === 0 ? <MobileEntryEmpty text={t('empty_roster')} /> : (
        <MobileScoreEntry students={roster.data!.data} drafts={drafts} saved={saved} suggestions={suggestions} threshold={6} withProgress={false}
          onChange={(sid, patch) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setMsg(null) }}
          onSuggestionDone={(s) => setSuggestions((all) => all.filter((x) => !(x.student_id === s.student_id && x.criterion === s.criterion)))}
          onSave={() => save.mutate()} saving={save.isPending} dirty={false} saveLabel={t('save')} />
      )}
      <MobileToast message={toast?.text ?? null} tone={toast?.tone} onDone={clearToast} />
    </div>
    <div className="hidden space-y-4 lg:block">
      <FilterBar layout="grid" label={t('monthly.circle')} className="sm:grid-cols-3">
        <SelectField label={t('monthly.circle')} value={lessonId ?? ''} onChange={(e) => setLessonId(Number(e.target.value))}
          options={(lessons.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))} />
        <TextInput label={t('monthly.period')} type="month" value={period} max={new Date().toISOString().slice(0, 7)} onChange={(e) => e.target.value && setPeriod(e.target.value)} />
        <p className="self-end text-xs text-ink/55">{t('monthly.hint')}</p>
      </FilterBar>
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {!lessonId ? (
        <EmptyCard icon="lessons" title={t('monthly.choose_circle')} />
      ) : roster.isLoading || existing.isLoading ? <LoadingState /> : (roster.data?.data.length ?? 0) === 0 ? (
        <EmptyCard icon="students" title={t('empty_roster')} />
      ) : (
        <>
          <ScoreGrid students={roster.data!.data} drafts={drafts} saved={saved} suggestions={suggestions} threshold={6} withProgress={false}
            onChange={(sid, patch) => { setDrafts((ds) => ({ ...ds, [sid]: { ...ds[sid], ...patch } })); setMsg(null) }}
            onSuggestionDone={(s) => setSuggestions((all) => all.filter((x) => !(x.student_id === s.student_id && x.criterion === s.criterion)))} />
          <div className="flex justify-end">
            <PrimaryButton loading={save.isPending} disabled={!Object.values(drafts).some(isComplete)} onClick={() => save.mutate()}>{save.isPending ? t('saving') : t('save')}</PrimaryButton>
          </div>
        </>
      )}
    </div>
    </>
  )
}
