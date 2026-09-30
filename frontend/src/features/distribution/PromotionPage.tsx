import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { distributionApi, type Decision, type PlaceResult } from '../../api/distribution'
import { useTerm } from '../../app/term'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap, inputClass } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { OptionsError, ResultsList, useClassOptions, useDistOptions } from './shared'

type Choice = { decision: Decision | 'skip'; lesson_id: number | '' }

/** ترفيع الطلبة: end-of-term decision per student of a level (promote, repeat, graduate), enrolled into the target term. */
export default function PromotionPage() {
  const { t, i18n } = useTranslation('distribution')
  const n = (v: number) => formatNumber(v, i18n.language)
  const qc = useQueryClient()
  const { terms, selected, current } = useTerm()
  const options = useDistOptions()
  const levels = options.data?.levels ?? []
  const [fromTerm, setFromTerm] = useState<number | ''>(selected?.id ?? current?.id ?? '')
  const [toTerm, setToTerm] = useState<number | ''>(() => terms.find((x) => x.id !== (selected?.id ?? current?.id))?.id ?? '')
  const [fromLevel, setFromLevel] = useState<number | ''>('')
  const [toLevel, setToLevel] = useState<number | ''>('')
  const [markGraduated, setMarkGraduated] = useState(false)
  const [choices, setChoices] = useState<Record<number, Choice>>({})
  const [outcome, setOutcome] = useState<{ message: string; results: PlaceResult[] } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const ready = fromTerm !== '' && toTerm !== '' && fromLevel !== '' && fromTerm !== toTerm
  const view = useQuery({
    queryKey: ['distribution-promotion', fromTerm, fromLevel, toTerm, toLevel],
    queryFn: () => distributionApi.promotion({ from_term_id: Number(fromTerm), from_level_id: Number(fromLevel), to_term_id: Number(toTerm), to_level_id: toLevel || undefined }),
    enabled: ready,
  })
  const data = view.data
  const targetLevel = toLevel || data?.to_level?.id || ''
  const promoteOptions = useClassOptions(data?.promote_classes ?? [])
  const repeatOptions = useClassOptions(data?.repeat_classes ?? [])
  const open = (data?.students ?? []).filter((s) => !s.decision)
  const choiceOf = (id: number): Choice => choices[id] ?? { decision: 'skip', lesson_id: '' }
  const setChoice = (id: number, c: Partial<Choice>) => setChoices((cs) => ({ ...cs, [id]: { ...choiceOf(id), ...c } }))
  const chosen = open.filter((s) => choiceOf(s.id).decision !== 'skip')

  const run = useMutation({
    mutationFn: () => distributionApi.promote({
      from_term_id: Number(fromTerm), from_level_id: Number(fromLevel), to_term_id: Number(toTerm), to_level_id: Number(targetLevel), mark_graduated: markGraduated,
      decisions: chosen.map((s) => ({ student_id: s.id, decision: choiceOf(s.id).decision as Decision, lesson_id: choiceOf(s.id).lesson_id === '' ? null : Number(choiceOf(s.id).lesson_id) })),
    }),
    onSuccess: (r) => {
      setOutcome({ message: r.message, results: r.data })
      setChoices({})
      void qc.invalidateQueries({ queryKey: ['distribution-promotion'] })
    },
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })

  const termOptions = [{ value: '', label: t('choose_term') }, ...terms.map((x) => ({ value: String(x.id), label: x.name }))]
  const levelOptions = [{ value: '', label: t('choose_level') }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.promote_students')} subtitle={t('promotion.subtitle')} />
      </div>
      {options.isLoading ? <LoadingState /> : options.isError ? <OptionsError error={options.error} onRetry={() => void options.refetch()} /> : (
        <>
          <section className={`${SURFACE} grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4`} aria-label={t('promotion.setup')}>
            <SelectField label={t('promotion.from_term')} value={String(fromTerm)} onChange={(e) => setFromTerm(e.target.value ? Number(e.target.value) : '')} options={termOptions} />
            <SelectField label={t('promotion.from_level')} value={String(fromLevel)} onChange={(e) => { setFromLevel(e.target.value ? Number(e.target.value) : ''); setToLevel('') }} options={levelOptions} />
            <SelectField label={t('promotion.to_term')} value={String(toTerm)} onChange={(e) => setToTerm(e.target.value ? Number(e.target.value) : '')} options={termOptions} />
            <SelectField label={t('promotion.to_level')} value={String(targetLevel)} onChange={(e) => setToLevel(e.target.value ? Number(e.target.value) : '')} options={levelOptions} />
          </section>
          {fromTerm !== '' && fromTerm === toTerm && <Notice tone="error">{t('promotion.same_term')}</Notice>}
          {error && <Notice tone="error">{error}</Notice>}
          {outcome && <ResultsList message={outcome.message} results={outcome.results} />}

          {!ready ? <Notice tone="info">{t('promotion.choose')}</Notice> : view.isLoading ? <LoadingState /> : view.isError ? <ErrorState onRetry={() => void view.refetch()} /> : !data || data.students.length === 0 ? (
            <EmptyCard icon="students" title={t('promotion.empty')} />
          ) : (
            <>
              {data.promote_classes.length === 0 && <Notice tone="info">{t('promotion.no_target_classes', { level: data.to_level?.name ?? '—', term: data.to_term.name })}</Notice>}
              <div className="flex flex-wrap items-center gap-2">
                <SecondaryButton onClick={() => setChoices(Object.fromEntries(open.map((s) => [s.id, { decision: 'promote', lesson_id: '' }])))}>{t('promotion.all_promote')}</SecondaryButton>
                <SecondaryButton onClick={() => setChoices({})}>{t('promotion.clear')}</SecondaryButton>
                <label className="flex items-center gap-2 text-sm text-ink/80">
                  <input type="checkbox" className="size-4 accent-brand-700" checked={markGraduated} onChange={(e) => setMarkGraduated(e.target.checked)} />
                  {t('promotion.mark_graduated')}
                </label>
                <PrimaryButton className="ms-auto" disabled={chosen.length === 0 || targetLevel === ''} loading={run.isPending} onClick={() => { setError(null); run.mutate() }}>
                  {t('promotion.run', { count: chosen.length, n: n(chosen.length) })}
                </PrimaryButton>
              </div>
              <TableWrap surface>
                <table className="w-full min-w-[48rem] text-sm">
                  <thead className={TABLE_HEAD}>
                    <tr>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('student')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('promotion.class_now')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('promotion.decision')}</th>
                      <th scope="col" className="px-3 py-2 text-start font-medium">{t('promotion.target_class')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-ink/6">
                    {data.students.map((s) => {
                      const c = choiceOf(s.id)
                      return (
                        <tr key={s.id}>
                          <td className="px-3 py-2">
                            <span dir="auto" className="block font-medium text-ink">{s.full_name}</span>
                            <span className="block text-xs tabular-nums text-ink/50">{s.student_no}{s.age !== null && <span className="ms-2">{t('age_years', { age: n(s.age), count: s.age })}</span>}</span>
                          </td>
                          <td className="px-3 py-2 text-ink/70">{s.lesson?.name ?? '—'}</td>
                          {s.decision ? (
                            <td colSpan={2} className="px-3 py-2">
                              <Badge tone={s.decision.decision === 'graduate' ? 'gold' : 'brand'}>{s.decision.label}</Badge>
                              {s.decision.to_lesson && <span className="ms-2 text-ink/60">{s.decision.to_lesson}</span>}
                            </td>
                          ) : (
                            <>
                              <td className="px-3 py-2">
                                <label className="sr-only" htmlFor={`d-${s.id}`}>{t('promotion.decision')}</label>
                                <select id={`d-${s.id}`} className={inputClass('sm', 'w-40 text-sm')} value={c.decision} onChange={(e) => setChoice(s.id, { decision: e.target.value as Choice['decision'], lesson_id: '' })}>
                                  {(['skip', 'promote', 'repeat', 'graduate'] as const).map((d) => <option key={d} value={d}>{t(`promotion.decisions.${d}`)}</option>)}
                                </select>
                              </td>
                              <td className="px-3 py-2">
                                {(c.decision === 'promote' || c.decision === 'repeat') ? (
                                  <>
                                    <label className="sr-only" htmlFor={`c-${s.id}`}>{t('promotion.target_class')}</label>
                                    <select id={`c-${s.id}`} className={inputClass('sm', 'w-56 max-w-full text-sm')} value={String(c.lesson_id)} onChange={(e) => setChoice(s.id, { lesson_id: e.target.value ? Number(e.target.value) : '' })}>
                                      {(c.decision === 'promote' ? promoteOptions : repeatOptions).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                    </select>
                                  </>
                                ) : <span className="text-ink/40">{s.target_class ? s.target_class.name : '—'}</span>}
                              </td>
                            </>
                          )}
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </TableWrap>
              <p className="text-xs text-ink/55">{t('promotion.history_note')}</p>
            </>
          )}
        </>
      )}
    </div>
  )
}
