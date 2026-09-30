import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { criteriaApi, type Criterion } from '../../api/education'
import { parseApiError, type FieldErrors } from '../../api/client'
import { useAuth } from '../../app/AuthContext'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, SURFACE, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'

/**
 * U5 التقييمات: the criteria each subject is evaluated on (they become the columns of its evaluation sheet).
 * Quran's four core criteria are renamed or reordered only.
 */
export default function CriteriaPage() {
  const { t, i18n } = useTranslation('evaluationCriteria')
  const locale = i18n.language
  const { can } = useAuth()
  const canManage = can('evaluation_criteria.manage')
  const qc = useQueryClient()
  const [subjectId, setSubjectId] = useState<number | undefined>(undefined)
  const q = useQuery({ queryKey: ['evaluation-criteria', subjectId ?? null], queryFn: () => criteriaApi.list(subjectId) })
  const [edit, setEdit] = useState<Criterion | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(criteriaApi.remove, [['evaluation-criteria']], t('delete_confirm'))
  const reorder = useMutation({
    mutationFn: (ids: number[]) => criteriaApi.reorder(q.data!.subject_id, ids),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['evaluation-criteria'] }),
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const rows = q.data?.data ?? []
  const move = (i: number, by: -1 | 1) => {
    const ids = rows.map((r) => r.id)
    const j = i + by
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    reorder.mutate(ids)
  }
  const subject = q.data?.subjects.find((s) => s.id === q.data?.subject_id)

  return (
    <div className="space-y-5">
      <div className="hidden lg:block"><PageBand title={t('nav:menu.evaluation_criteria')} subtitle={t('subtitle')} /></div>
      {q.isLoading && !q.data ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : q.data && (
        <>
          <FilterBar label={t('subject')}>
            <SelectField label={t('subject')} hideLabel className="sm:w-72" value={String(q.data.subject_id)} onChange={(e) => setSubjectId(Number(e.target.value))}
              options={q.data.subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
            {subject && <p className="text-sm text-ink/60 sm:ms-auto">{t('count', { n: formatNumber(subject.criteria_count, locale) })}</p>}
          </FilterBar>
          <Toolbar label={canManage ? t('new') : undefined} onAdd={canManage ? () => setEdit('new') : undefined} notice={notice} />
          {subject?.code === 'quran' && <Notice tone="info">{t('system_hint')}</Notice>}
          {rows.length === 0 ? <EmptyCard icon="evaluation" title={t('empty')} /> : (
            <ol className="space-y-2">
              {rows.map((c, i) => (
                <li key={c.id} className={`${SURFACE} flex flex-wrap items-center gap-3 p-4 ${c.is_active ? '' : 'opacity-60'}`}>
                  <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-semibold tabular-nums text-brand-700">{formatNumber(i + 1, locale)}</span>
                  <div className="min-w-0 flex-1">
                    <p dir="auto" className="font-semibold text-ink">{c.name}</p>
                    <p className="text-xs text-ink/55">
                      {t('out_of', { n: formatNumber(c.max_score, locale) })}، {t('weight_n', { n: formatNumber(c.weight, locale) })}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {c.is_system && <Badge tone="info">{t('system')}</Badge>}
                    {!c.is_active && <Badge>{t('inactive')}</Badge>}
                    {c.in_use && <Badge tone="brand">{t('in_use')}</Badge>}
                  </div>
                  {canManage && (
                    <div className="flex shrink-0">
                      <IconButton icon="chevron" iconClassName="-rotate-90" label={t('up')} disabled={i === 0 || reorder.isPending} onClick={() => move(i, -1)} />
                      <IconButton icon="chevron" iconClassName="rotate-90" label={t('down')} disabled={i === rows.length - 1 || reorder.isPending} onClick={() => move(i, 1)} />
                      <IconButton icon="edit" label={t('edit')} onClick={() => setEdit(c)} />
                      {!c.is_system && <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(c.id)} />}
                    </div>
                  )}
                </li>
              ))}
            </ol>
          )}
        </>
      )}
      {edit && q.data && (
        <CriterionDialog row={edit === 'new' ? undefined : edit} subjectId={q.data.subject_id} onClose={() => setEdit(null)}
          onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function CriterionDialog({ row, subjectId, onClose, onSaved }: { row?: Criterion; subjectId: number; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('evaluationCriteria')
  const qc = useQueryClient()
  const system = !!row?.is_system
  const [form, setForm] = useState({ name_ar: row?.name_ar ?? '', name_en: row?.name_en ?? '', max_score: String(row?.max_score ?? 10), weight: String(row?.weight ?? 1), is_active: row?.is_active ?? true })
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = system
        ? { name_ar: form.name_ar, name_en: form.name_en }
        : { name_ar: form.name_ar, name_en: form.name_en, max_score: Number(form.max_score), weight: Number(form.weight), is_active: form.is_active }
      return row ? criteriaApi.update(row.id, d) : criteriaApi.create({ ...d, subject_id: subjectId })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['evaluation-criteria'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  return (
    <Modal title={row ? t('title_edit') : t('title_new')} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      {system && <Notice tone="info">{t('system_hint')}</Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.name_ar?.[0]}><TextInput label={t('name_ar')} dir="rtl" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} /></Field>
        <Field error={errors.name_en?.[0]}><TextInput label={t('name_en')} dir="ltr" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} /></Field>
        {!system && (
          <>
            <Field error={errors.max_score?.[0]}><TextInput label={t('max_score')} type="number" min={1} max={100} dir="ltr" value={form.max_score} onChange={(e) => setForm({ ...form, max_score: e.target.value })} /></Field>
            <Field error={errors.weight?.[0]}><TextInput label={t('weight')} type="number" min={1} max={100} dir="ltr" value={form.weight} onChange={(e) => setForm({ ...form, weight: e.target.value })} /></Field>
          </>
        )}
      </div>
      {!system && (
        <label className="flex items-center gap-2 text-sm text-ink/80">
          <input type="checkbox" className="size-4 accent-brand-700" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
          {t('is_active')}
        </label>
      )}
    </Modal>
  )
}
