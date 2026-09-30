import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { termSetupApi, type LevelSubject, type PlanItem, type PlanWeek } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import Icon from '../../components/Icon'
import { EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, SURFACE, TABLE_HEAD, TableWrap, TextArea, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { useCanManage, useSetupOptions } from './shared'
import { useTermScope } from '../../app/term'

/** Level picker shared by الخطة and عرض الخطة. */
function LevelPicked({ children }: { children: (levelId: number) => React.ReactNode }) {
  const { t } = useTranslation('termSetup')
  const options = useSetupOptions()
  const levels = options.data?.levels ?? []
  const [levelId, setLevelId] = useState<number>(() => levels[0]?.id ?? 0)

  if (levels.length === 0) return <EmptyCard icon="lessons" title={t('no_levels')} />

  return (
    <div className="space-y-4">
      <FilterBar>
        <SelectField label={t('fields.level')} hideLabel className="sm:w-56" value={String(levelId)} onChange={(e) => setLevelId(Number(e.target.value))}
          options={levels.map((l) => ({ value: String(l.id), label: l.name }))} />
      </FilterBar>
      {children(levelId)}
    </div>
  )
}

/** الخطة: one subject of a level, week by week (read-only for term_setup.view). */
export function PlanTab() {
  return <LevelPicked>{(id) => <PlanEditor levelId={id} />}</LevelPicked>
}

/** عرض الخطة: the level's whole plan, weeks × subjects. */
export function PlanViewTab() {
  return <LevelPicked>{(id) => <PlanGrid levelId={id} />}</LevelPicked>
}

/** "Week n" with its start date on a second line (a "·" beside Arabic-Indic digits reads like a zero). */
function WeekLabel({ w }: { w: PlanWeek }) {
  const { t, i18n } = useTranslation('termSetup')
  return (
    <>
      <span className="block">{t('plan.week', { n: formatNumber(w.week_no, i18n.language) })}</span>
      {w.starts_on && <span className="block text-xs font-normal text-ink/50">{formatDate(w.starts_on, i18n.language, { day: 'numeric', month: 'short' })}</span>}
    </>
  )
}

function PlanEditor({ levelId }: { levelId: number }) {
  const { t, i18n } = useTranslation('termSetup')
  const ts = useTermScope()
  const subjects = useQuery({ queryKey: ['term-setup-level-subjects', levelId], queryFn: () => termSetupApi.levelSubjects({ level_id: levelId }) })
  const [picked, setPicked] = useState<number | null>(null)
  const list = subjects.data?.data ?? []
  const current: LevelSubject | undefined = list.find((s) => s.id === picked) ?? list[0]
  const plan = useQuery({ queryKey: ['term-setup-plan', current?.id], queryFn: () => termSetupApi.plan(current!.id), enabled: !!current })
  const [add, setAdd] = useState<{ week: number; item?: PlanItem } | null>(null)
  const canManage = useCanManage()
  const { notice, setNotice, remove } = useRemove(termSetupApi.removePlanItem, [['term-setup-plan'], ['term-setup-plan-view']], t('plan.delete_confirm'))

  if (subjects.isLoading) return <LoadingState />
  if (subjects.isError) return <ErrorState onRetry={() => void subjects.refetch()} />
  if (!current) return <EmptyCard icon="evaluation" title={t('plan.no_subjects', { scope: ts.scope() })} />

  return (
    <div className="space-y-4">
      <SelectField label={t('fields.subject')} className="max-w-sm" value={String(current.id)} onChange={(e) => setPicked(Number(e.target.value))}
        options={list.map((s) => ({ value: String(s.id), label: s.subject.name }))} />
      <Toolbar notice={notice} />
      {plan.isLoading ? <LoadingState /> : plan.isError || !plan.data ? <ErrorState onRetry={() => void plan.refetch()} /> : (
        <ol className={`${SURFACE} divide-y divide-ink/6`}>
          {plan.data.weeks.map((w) => {
            const items = plan.data.data.filter((p) => p.week_no === w.week_no)
            return (
              <li key={w.week_no} className="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3">
                <p className="w-full shrink-0 text-sm font-medium text-ink/70 sm:w-32 sm:pt-1"><WeekLabel w={w} /></p>
                <ul className="flex min-w-0 flex-[1_1_12rem] flex-wrap gap-2">
                  {items.map((p) => (
                    <li key={p.id} className="inline-flex max-w-full items-center gap-1 rounded-xl border border-ink/10 bg-page/60 py-1 pe-1 ps-3 text-sm">
                      <span dir="auto" className="min-w-0 break-words text-ink">{p.display_title}{p.target_ayahs ? <span className="ms-1 text-xs tabular-nums text-ink/55">({t('plan.ayahs', { n: formatNumber(p.target_ayahs, i18n.language) })})</span> : null}</span>
                      {canManage && <IconButton icon="edit" label={t('edit')} onClick={() => setAdd({ week: w.week_no, item: p })} />}
                      {canManage && <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(p.id)} />}
                    </li>
                  ))}
                  {canManage && <li>
                    <button type="button" onClick={() => setAdd({ week: w.week_no })}
                      className="inline-flex min-h-9 items-center gap-1 rounded-xl border border-dashed border-ink/20 px-3 text-sm text-brand-700 hover:bg-brand-50">
                      <Icon name="plus" className="size-4" />{t('plan.add')}
                    </button>
                  </li>}
                </ul>
              </li>
            )
          })}
        </ol>
      )}
      {add && (
        <PlanItemDialog levelSubject={current} week={add.week} item={add.item} weeks={plan.data?.weeks.length ?? 16}
          onClose={() => setAdd(null)} onSaved={(m) => { setAdd(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function PlanItemDialog({ levelSubject, week, item, weeks, onClose, onSaved }: { levelSubject: LevelSubject; week: number; item?: PlanItem; weeks: number; onClose: () => void; onSaved: (m: string) => void }) {
  const { t, i18n } = useTranslation('termSetup')
  const qc = useQueryClient()
  const lessons = useQuery({
    queryKey: ['term-setup-subject-lessons', levelSubject.subject.id, levelSubject.level.id, 'active'],
    queryFn: () => termSetupApi.subjectLessons({ subject_id: levelSubject.subject.id, level_id: levelSubject.level.id, active: true }),
  })
  const [form, setForm] = useState({ week_no: item?.week_no ?? week, subject_lesson_id: item?.subject_lesson_id ?? null as number | null, title: item?.title ?? '', notes: item?.notes ?? '', target_ayahs: item?.target_ayahs ?? null as number | null })
  /** U4: a Quran week can say how many ayahs it should add; the dashboard's "behind plan" follows it. */
  const isQuran = levelSubject.subject.code === 'quran'
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, title: form.title || null, notes: form.notes || null }
      return item ? termSetupApi.updatePlanItem(item.id, d) : termSetupApi.createPlanItem({ ...d, level_subject_id: levelSubject.id })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['term-setup-plan'] }); void qc.invalidateQueries({ queryKey: ['term-setup-plan-view'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })

  return (
    <Modal title={t('plan.item_title', { subject: levelSubject.subject.name })} onClose={onClose}
      footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <SelectField label={t('fields.week')} value={String(form.week_no)} onChange={(e) => setForm({ ...form, week_no: Number(e.target.value) })}
        options={Array.from({ length: Math.max(weeks, form.week_no) }, (_, i) => ({ value: String(i + 1), label: t('plan.week', { n: formatNumber(i + 1, i18n.language) }) }))} />
      <Field error={errors.subject_lesson_id?.[0]}>
        <SelectField label={t('fields.lesson')} value={String(form.subject_lesson_id ?? '')} onChange={(e) => setForm({ ...form, subject_lesson_id: e.target.value ? Number(e.target.value) : null })}
          options={[{ value: '', label: t('plan.no_lesson') }, ...(lessons.data ?? []).map((l) => ({ value: String(l.id), label: l.title }))]} />
      </Field>
      <Field error={errors.title?.[0]}>
        <TextInput label={t('fields.plan_title')} dir="auto" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
      </Field>
      <p className="text-xs text-ink/55">{t('plan.title_hint')}</p>
      {isQuran && (
        <Field error={errors.target_ayahs?.[0]}>
          <TextInput label={t('fields.target_ayahs')} type="number" min={1} max={6236} value={form.target_ayahs ?? ''} onChange={(e) => setForm({ ...form, target_ayahs: e.target.value ? Number(e.target.value) : null })} />
          <p className="mt-1 text-xs text-ink/55">{t('plan.target_hint')}</p>
        </Field>
      )}
      <TextArea label={t('fields.notes')} rows={2} dir="auto" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
    </Modal>
  )
}

/** عرض الخطة: weeks down the side, the level's subjects across. */
function PlanGrid({ levelId }: { levelId: number }) {
  const { t, i18n } = useTranslation('termSetup')
  const ts = useTermScope()
  const q = useQuery({ queryKey: ['term-setup-plan-view', levelId], queryFn: () => termSetupApi.planView(levelId), enabled: levelId > 0 })

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
  if (q.data.subjects.length === 0) return <EmptyCard icon="evaluation" title={t('plan.no_subjects', { scope: ts.scope() })} />

  return (
    <TableWrap surface>
      <table className="w-full min-w-[40rem] text-sm">
        <thead className={TABLE_HEAD}>
          <tr>
            <th scope="col" className="px-4 py-3 text-start font-medium">{t('fields.week')}</th>
            {q.data.subjects.map((s) => (
              <th key={s.id} scope="col" className="px-4 py-3 text-start font-medium">
                <span dir="auto" className="block text-ink">{s.subject.name}</span>
                {s.teacher && <bdi className="block text-xs font-normal text-ink/50">{s.teacher.name}</bdi>}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-ink/6">
          {q.data.weeks.map((w) => (
            <tr key={w.week_no} className="align-top">
              <th scope="row" className="whitespace-nowrap px-4 py-3 text-start font-medium text-ink/70"><WeekLabel w={w} /></th>
              {q.data.subjects.map((s) => {
                const items = s.items.filter((p) => p.week_no === w.week_no)
                return (
                  <td key={s.id} className="px-4 py-3">
                    {items.length === 0 ? <span className="text-ink/30">—</span> : (
                      <ul className="space-y-1">{items.map((p) => <li key={p.id} dir="auto" className="text-ink">{p.display_title}{p.target_ayahs ? <span className="ms-1 text-xs tabular-nums text-ink/55">({t('plan.ayahs', { n: formatNumber(p.target_ayahs, i18n.language) })})</span> : null}</li>)}</ul>
                    )}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </TableWrap>
  )
}
