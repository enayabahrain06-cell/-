import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { COMPONENT_KINDS, gradesApi, type ComponentKind, type DistributionData, type GradeComponent } from '../../api/grades'
import { parseApiError, type FieldErrors } from '../../api/client'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, SURFACE, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { useTermScope } from '../../app/term'

/** توزيع الدرجات: the grade components of a level subject of the term (weights are percent shares of 100). */
export default function GradeDistributionPage() {
  const { t, i18n } = useTranslation('grades')
  const ts = useTermScope()
  const locale = i18n.language
  const qc = useQueryClient()
  const [levelId, setLevelId] = useState<number | ''>('')
  const [lsId, setLsId] = useState<number | ''>('')
  const [edit, setEdit] = useState<GradeComponent | 'new' | null>(null)
  const q = useQuery({ queryKey: ['grade-components', lsId], queryFn: () => gradesApi.distribution({ level_subject_id: lsId === '' ? undefined : lsId }), placeholderData: (prev) => prev })
  const { notice, setNotice, remove } = useRemove(gradesApi.removeComponent, [['grade-components']], t('distribution.delete_confirm'))
  const reorder = useMutation({
    mutationFn: (ids: number[]) => gradesApi.reorder(Number(lsId), ids),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['grade-components'] }),
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const d = q.data
  const n = (v: number) => formatNumber(v, locale, { maximumFractionDigits: 2 })
  const levels = [...new Map((d?.level_subjects ?? []).map((ls) => [ls.level.id, ls.level])).values()]
  const subjects = (d?.level_subjects ?? []).filter((ls) => ls.level.id === levelId)
  const current = lsId !== '' && d?.level_subject?.id === lsId
  const rows = current ? (d?.components ?? []) : []
  const move = (i: number, by: -1 | 1) => {
    const ids = rows.map((r) => r.id)
    const j = i + by
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    reorder.mutate(ids)
  }
  const total = current ? (d?.summary?.weight_total ?? 0) : 0

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.grade_distribution')} subtitle={d ? t('distribution.subtitle', { term: ts.label(d.term.name) }) : undefined} /></div>
      {q.isLoading && !d ? <LoadingState /> : q.isError ? (
        isAxiosError(q.error) && q.error.response?.status === 422 ? <Notice tone="info">{parseApiError(q.error).message}</Notice> : <ErrorState message={parseApiError(q.error).message} onRetry={() => void q.refetch()} />
      ) : d && (d.level_subjects.length === 0 ? <EmptyCard icon="evaluation" title={t('distribution.no_level_subjects', { scope: ts.scope() })} /> : (
        <>
          <FilterBar label={t('common.filters')}>
            <SelectField label={t('common.level')} className="sm:w-60" value={String(levelId)} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setLsId('') }}
              options={[{ value: '', label: '—' }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
            <SelectField label={t('common.subject')} className="sm:w-60" value={String(lsId)} disabled={levelId === ''} onChange={(e) => setLsId(e.target.value ? Number(e.target.value) : '')}
              options={[{ value: '', label: '—' }, ...subjects.map((s) => ({ value: String(s.id), label: s.subject.name }))]} />
          </FilterBar>
          {lsId === '' ? <EmptyCard icon="chart" title={t('distribution.pick')} /> : !current ? <LoadingState /> : (
            <>
              <Toolbar label={t('distribution.new')} onAdd={() => setEdit('new')} notice={notice}>
                <p className="me-auto self-center text-sm text-ink/70">
                  {t('distribution.weight_total', { total: n(total) })}{' '}
                  <Badge tone={Math.abs(total - 100) < 0.001 ? 'brand' : 'gold'}>{Math.abs(total - 100) < 0.001 ? t('distribution.complete') : t('distribution.left', { left: n(Math.max(0, 100 - total)) })}</Badge>
                </p>
              </Toolbar>
              {rows.length > 0 && Math.abs(total - 100) >= 0.001 && <Notice tone="info">{t('distribution.not_100', { total: n(total) })}</Notice>}
              {rows.length === 0 ? <EmptyCard icon="chart" title={t('distribution.empty')} body={t('distribution.empty_body')} /> : (
                <ol className="space-y-2">
                  {rows.map((c, i) => (
                    <li key={c.id} className={`${SURFACE} flex flex-wrap items-center gap-3 p-4`}>
                      <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-semibold tabular-nums text-brand-700">{formatNumber(i + 1, locale)}</span>
                      <div className="min-w-0 flex-[1_1_12rem]">
                        <p dir="auto" className="font-semibold text-ink">{c.name}</p>
                        <p className="text-xs text-ink/60">
                          {t('distribution.marks', { max: n(c.max_marks) })}
                          {c.exam ? <>{t('common.sep')}<bdi>{t('distribution.linked', { name: c.exam.name })}</bdi></> : c.kind === 'exam' ? <>{t('common.sep')}{t('distribution.no_exam')}</> : null}
                          {c.entries ? <>{t('common.sep')}{t('distribution.entries', { n: formatNumber(c.entries, locale) })}</> : null}
                        </p>
                      </div>
                      <Badge tone={c.kind === 'exam' ? 'info' : 'muted'}>{t(`kind.${c.kind}`)}</Badge>
                      <span className="text-lg font-semibold tabular-nums text-ink">{t('common.pct', { n: n(c.weight) })}</span>
                      <span className="flex gap-1 text-ink/60">
                        <IconButton size="md" icon="chevron" iconClassName="-rotate-90" label={t('distribution.move_up')} disabled={i === 0 || reorder.isPending} onClick={() => move(i, -1)} />
                        <IconButton size="md" icon="chevron" iconClassName="rotate-90" label={t('distribution.move_down')} disabled={i === rows.length - 1 || reorder.isPending} onClick={() => move(i, 1)} />
                        <IconButton size="md" icon="edit" label={t('distribution.edit')} onClick={() => setEdit(c)} />
                        <IconButton size="md" icon="trash" tone="danger" label={t('distribution.delete')} onClick={() => remove(c.id)} />
                      </span>
                    </li>
                  ))}
                </ol>
              )}
            </>
          )}
        </>
      ))}
      {edit && d && lsId !== '' && (
        <ComponentDialog data={d} levelSubjectId={lsId} component={edit === 'new' ? undefined : edit} onClose={() => setEdit(null)}
          onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }); void qc.invalidateQueries({ queryKey: ['grade-components'] }) }} />
      )}
    </div>
  )
}

function ComponentDialog({ data, levelSubjectId, component, onClose, onSaved }: { data: DistributionData; levelSubjectId: number; component?: GradeComponent; onClose: () => void; onSaved: (m: string) => void }) {
  const { t, i18n } = useTranslation('grades')
  const [nameAr, setNameAr] = useState(component?.name_ar ?? '')
  const [nameEn, setNameEn] = useState(component?.name_en ?? '')
  const [kind, setKind] = useState<ComponentKind>(component?.kind ?? 'homework')
  const [max, setMax] = useState(component ? String(component.max_marks) : '10')
  const [weight, setWeight] = useState(component ? String(component.weight) : '')
  const [examId, setExamId] = useState(component?.exam ? String(component.exam.id) : '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [msg, setMsg] = useState<string | null>(null)
  const exams = (data.exams ?? []).filter((e) => e.component_id === null || e.component_id === component?.id)
  const linked = exams.find((e) => String(e.id) === examId)
  const save = useMutation({
    mutationFn: () => {
      const body = { name_ar: nameAr, name_en: nameEn || null, kind, max_marks: max === '' ? null : Number(max), weight: Number(weight || 0), exam_id: kind === 'exam' && examId ? Number(examId) : null }
      return component ? gradesApi.updateComponent(component.id, body) : gradesApi.createComponent({ ...body, level_subject_id: levelSubjectId })
    },
    onSuccess: (r) => onSaved(r.summary.warning ? `${r.message} ${r.summary.warning}` : r.message),
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setMsg(Object.keys(p.fields).length ? null : p.message) },
  })
  const e = (k: string) => errors[k]?.[0]

  return (
    <Modal title={component ? t('distribution.edit') : t('distribution.new')} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      {msg && <Notice tone="error">{msg}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={e('name_ar')}><TextInput label={t('distribution.name_ar')} dir="rtl" value={nameAr} onChange={(x) => setNameAr(x.target.value)} /></Field>
        <Field error={e('name_en')}><TextInput label={t('distribution.name_en')} dir="ltr" value={nameEn} onChange={(x) => setNameEn(x.target.value)} /></Field>
        <SelectField label={t('distribution.kind')} value={kind} error={e('kind')} onChange={(x) => setKind(x.target.value as ComponentKind)}
          options={COMPONENT_KINDS.map((k) => ({ value: k, label: t(`kind.${k}`) }))} />
        <Field error={e('weight')}><TextInput label={t('distribution.weight')} type="number" min={0} max={100} step="0.5" inputMode="decimal" value={weight} onChange={(x) => setWeight(x.target.value)} /></Field>
        {kind === 'exam' && (
          <SelectField className="sm:col-span-2" label={t('distribution.exam')} value={examId} error={e('exam_id')} onChange={(x) => setExamId(x.target.value)}
            options={[{ value: '', label: t('distribution.exam_later') }, ...exams.map((x) => ({ value: String(x.id), label: `${x.name}${x.where ? ` (${x.where})` : ''}` }))]} />
        )}
        <Field error={e('max_marks')}>
          <TextInput label={t('distribution.max_marks')} type="number" min={0.5} step="0.5" inputMode="decimal" disabled={!!linked}
            value={linked ? String(linked.total_marks) : max} onChange={(x) => setMax(x.target.value)} />
          {linked && <p className="mt-1 text-xs text-ink/55">{t('distribution.max_from_exam', { n: formatNumber(linked.total_marks, i18n.language) })}</p>}
        </Field>
      </div>
      {kind === 'exam' && exams.length === 0 && <Notice tone="info">{t('distribution.no_exams')}</Notice>}
      {e('component') && <Notice tone="error">{e('component')}</Notice>}
    </Modal>
  )
}
