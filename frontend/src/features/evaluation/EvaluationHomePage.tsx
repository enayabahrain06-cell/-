import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { evaluationsApi, type SavedScore, type Suggestion } from '../../api/evaluations'
import { lessonsApi, studentsApi } from '../../api/students'
import { parseApiError } from '../../api/client'
import SelectField from '../../components/SelectField'
import { EmptyState, PageBand } from '../../components/ornaments'
import { LoadingState, Notice, PrimaryButton, Segmented, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import AttendanceDayPage from '../attendance/AttendanceDayPage'
import ScoreGrid, { emptyDraft, isComplete, type Draft } from './ScoreGrid'

export default function EvaluationHomePage() {
  const { t } = useTranslation('evaluation')
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'monthly' ? 'monthly' : 'daily'

  return (
    <div className="space-y-5">
      <PageBand title={t('title')} subtitle={t('subtitle')} />
      <Segmented name="eval-tab" label={t('title')} value={tab}
        options={[{ value: 'daily', label: t('tabs.daily') }, { value: 'monthly', label: t('tabs.monthly') }]}
        onChange={(v) => { const n = new URLSearchParams(params); n.set('tab', v); setParams(n, { replace: true }) }} />
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
    },
    onError: (e) => setMsg({ tone: 'error', text: parseApiError(e).message }),
  })

  return (
    <div className="space-y-4">
      <div className="grid gap-3 rounded-2xl border border-ink/8 bg-white p-4 shadow-sm sm:grid-cols-3">
        <SelectField label={t('monthly.circle')} value={lessonId ?? ''} onChange={(e) => setLessonId(Number(e.target.value))}
          options={(lessons.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))} />
        <TextInput label={t('monthly.period')} type="month" value={period} max={new Date().toISOString().slice(0, 7)} onChange={(e) => e.target.value && setPeriod(e.target.value)} />
        <p className="self-end text-xs text-ink/55">{t('monthly.hint')}</p>
      </div>
      {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
      {!lessonId ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="lessons" title={t('monthly.choose_circle')} /></div>
      ) : roster.isLoading || existing.isLoading ? <LoadingState /> : (roster.data?.data.length ?? 0) === 0 ? (
        <div className="rounded-2xl border border-ink/8 bg-white shadow-sm"><EmptyState icon="students" title={t('empty_roster')} /></div>
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
  )
}
