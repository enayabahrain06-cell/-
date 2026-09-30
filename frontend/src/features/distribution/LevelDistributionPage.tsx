import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { distributionApi, type PlaceResult } from '../../api/distribution'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, PrimaryButton, SearchInput, Segmented, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { OptionsError, ResultsList, useClassOptions, useDistOptions } from './shared'
import { useTermScope } from '../../app/term'

type View = 'unplaced' | 'level'

/** توزيع المستويات: put the term's students in a class of a level (capacity, gender and age rules apply). */
export default function LevelDistributionPage() {
  const { t, i18n } = useTranslation('distribution')
  const ts = useTermScope()
  const n = (v: number) => formatNumber(v, i18n.language)
  const qc = useQueryClient()
  const options = useDistOptions()
  const [view, setView] = useState<View>('unplaced')
  const [filterLevel, setFilterLevel] = useState<number | ''>('')
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [levelId, setLevelId] = useState<number | ''>('')
  const [lessonId, setLessonId] = useState<number | ''>('')
  const [outcome, setOutcome] = useState<{ message: string; results: PlaceResult[] } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const students = useQuery({
    queryKey: ['distribution-students', view, filterLevel, search],
    queryFn: () => distributionApi.students({ view, level_id: filterLevel || undefined, search: search.trim() || undefined }),
    enabled: options.isSuccess && (view === 'unplaced' || filterLevel !== ''),
    placeholderData: keepPreviousData,
  })
  const rows = useMemo(() => students.data ?? [], [students.data])
  const levelClasses = (options.data?.classes ?? []).filter((c) => levelId !== '' && c.level_id === levelId)
  const classOptions = useClassOptions(levelClasses)

  const place = useMutation({
    mutationFn: () => distributionApi.place({ academic_term_id: options.data!.term.id, level_id: Number(levelId), lesson_id: lessonId === '' ? null : lessonId, student_ids: picked }),
    onSuccess: (r) => {
      setOutcome({ message: r.message, results: r.data })
      setPicked(r.data.filter((x) => !x.ok).map((x) => x.student_id))
      void qc.invalidateQueries({ queryKey: ['distribution-students'] })
      void qc.invalidateQueries({ queryKey: ['distribution-options'] })
    },
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })

  const toggle = (id: number) => setPicked((ps) => (ps.includes(id) ? ps.filter((x) => x !== id) : [...ps, id]))
  const allPicked = rows.length > 0 && rows.every((r) => picked.includes(r.id))
  const pickSuggested = () => { if (levelId !== '') setPicked(rows.filter((r) => r.suggested_level?.id === levelId).map((r) => r.id)) }
  const levels = options.data?.levels ?? []

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.level_distribution')} subtitle={options.data ? t('subtitle', { term: ts.label(options.data.term.name) }) : undefined} />
      </div>
      {options.isLoading ? <LoadingState /> : options.isError ? <OptionsError error={options.error} onRetry={() => void options.refetch()} /> : (
        <>
          <FilterBar label={t('filters')}>
            <Segmented name="dist-view" label={t('filters')} value={view} onChange={(v) => { setView(v); setPicked([]) }}
              options={[{ value: 'unplaced', label: t('view.unplaced') }, { value: 'level', label: t('view.level') }]} />
            {view === 'level' && (
              <SelectField label={t('level')} hideLabel className="sm:w-56" value={String(filterLevel)} onChange={(e) => { setFilterLevel(e.target.value ? Number(e.target.value) : ''); setPicked([]) }}
                options={[{ value: '', label: t('choose_level') }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
            )}
            <SearchInput label={t('search')} className="sm:w-64" value={search} onChange={(e) => setSearch(e.target.value)} />
          </FilterBar>

          <section className={`${SURFACE} grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto] lg:items-end`} aria-label={t('place_title')}>
            <SelectField label={t('level')} value={String(levelId)} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setLessonId('') }}
              options={[{ value: '', label: t('choose_level') }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
            <SelectField label={t('class')} value={String(lessonId)} disabled={levelId === ''} onChange={(e) => setLessonId(e.target.value ? Number(e.target.value) : '')} options={classOptions} />
            <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-1">
              <SecondaryButton disabled={levelId === ''} onClick={pickSuggested}>{t('pick_suggested')}</SecondaryButton>
              <PrimaryButton disabled={levelId === '' || picked.length === 0} loading={place.isPending} onClick={() => { setError(null); place.mutate() }}>
                {t('place_run', { count: picked.length, n: n(picked.length) })}
              </PrimaryButton>
            </div>
            {levelId !== '' && levelClasses.length === 0 && <div className="sm:col-span-2 lg:col-span-3"><Notice tone="info">{t('no_classes', { scope: ts.scope() })}</Notice></div>}
          </section>

          {error && <Notice tone="error">{error}</Notice>}
          {outcome && <ResultsList message={outcome.message} results={outcome.results} />}

          {view === 'level' && filterLevel === '' ? <Notice tone="info">{t('choose_level_hint', { scope: ts.scope() })}</Notice>
            : students.isLoading ? <LoadingState /> : students.isError ? <ErrorState onRetry={() => void students.refetch()} /> : rows.length === 0 ? (
              <EmptyCard icon="students" title={view === 'unplaced' ? t('empty_unplaced', { scope: ts.scope() }) : t('empty_level')} />
            ) : (
              <TableWrap surface>
                <table className="w-full min-w-[44rem] text-sm">
                  <thead className={TABLE_HEAD}>
                    <tr>
                      <th scope="col" className="w-10 px-3 py-2">
                        <input type="checkbox" className="size-4 accent-brand-700" aria-label={t('pick_all')} checked={allPicked}
                          onChange={() => setPicked(allPicked ? [] : rows.map((r) => r.id))} />
                      </th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('age')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('memorization')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('current')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('suggested')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink/6">
                    {rows.map((r) => (
                      <tr key={r.id} className={picked.includes(r.id) ? 'bg-brand-50/50' : ''}>
                        <td className="px-3 py-2">
                          <input type="checkbox" className="size-4 accent-brand-700" aria-label={r.full_name} checked={picked.includes(r.id)} onChange={() => toggle(r.id)} />
                        </td>
                        <td className="px-3 py-2">
                          <span dir="auto" className="block font-medium text-ink">{r.full_name}</span>
                          <span className="block text-xs tabular-nums text-ink/50">{r.student_no}</span>
                        </td>
                        <td className="px-3 py-2 tabular-nums">{r.age === null ? '—' : n(r.age)}</td>
                        <td className="px-3 py-2 text-ink/70">{r.memorization_level_label ?? '—'}</td>
                        <td className="px-3 py-2 text-ink/70">{r.current ? <><span className="block">{r.current.lesson}</span><span className="block text-xs text-ink/50">{r.current.level ?? t('no_level')}</span></> : <Badge tone="gold">{t('unplaced')}</Badge>}</td>
                        <td className="px-3 py-2">{r.suggested_level ? <Badge tone="info">{r.suggested_level.name}</Badge> : <span className="text-ink/40">—</span>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </TableWrap>
            )}
        </>
      )}
    </div>
  )
}
