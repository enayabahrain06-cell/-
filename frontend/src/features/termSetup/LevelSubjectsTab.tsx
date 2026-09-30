import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { termSetupApi, type LevelSubject, type Ref } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import Icon from '../../components/Icon'
import { Badge, EmptyCard, ErrorState, IconButton, LoadingState, Modal, SecondaryButton, SURFACE, TextArea, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { useCanManage, useSetupOptions } from './shared'
import { useTermScope } from '../../app/term'

/** مواد المستويات: one card per level listing its subjects for the term. */
export default function LevelSubjectsTab() {
  const { t, i18n } = useTranslation('termSetup')
  const ts = useTermScope()
  const canManage = useCanManage()
  const options = useSetupOptions()
  const q = useQuery({ queryKey: ['term-setup-level-subjects'], queryFn: () => termSetupApi.levelSubjects() })
  const [edit, setEdit] = useState<{ level: Ref; row?: LevelSubject } | null>(null)
  const { notice, setNotice, remove } = useRemove(termSetupApi.removeLevelSubject, [['term-setup-level-subjects']], t('level_subjects.delete_confirm', { scope: ts.scope() }))
  const levels = options.data?.levels ?? []

  return (
    <div className="space-y-4">
      <Toolbar notice={notice} />
      <p className="text-sm text-ink/60">{t('level_subjects.hint', { scope: ts.scope() })}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : levels.length === 0 ? (
        <EmptyCard icon="lessons" title={t('no_levels')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {levels.map((level) => {
            const rows = (q.data?.data ?? []).filter((r) => r.level.id === level.id)
            return (
              <li key={level.id} className={`${SURFACE} p-4`}>
                <div className="flex items-center justify-between gap-2">
                  <p dir="auto" className="font-semibold text-ink">{level.name}</p>
                  <Badge tone="muted">{t('level_subjects.count', { n: formatNumber(rows.length, i18n.language) })}</Badge>
                </div>
                {rows.length === 0 ? <p className="mt-2 text-sm text-ink/50">{t('level_subjects.none')}</p> : (
                  <ul className="mt-2 divide-y divide-ink/6">
                    {rows.map((r) => (
                      <li key={r.id} className="flex items-center gap-2 py-2 text-sm">
                        <div className="min-w-0 flex-1">
                          <p dir="auto" className="font-medium text-ink">{r.subject.name}</p>
                          <p className="text-xs text-ink/55">
                            <bdi>{r.teacher?.name ?? t('no_teacher')}</bdi>
                            {r.weekly_sessions ? <>{t('list_sep')}{t('level_subjects.weekly', { n: formatNumber(r.weekly_sessions, i18n.language) })}</> : null}
                          </p>
                        </div>
                        {canManage && (
                          <>
                            <IconButton icon="edit" label={t('edit')} onClick={() => setEdit({ level, row: r })} />
                            <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(r.id)} />
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {canManage && (
                  <SecondaryButton className="mt-3" onClick={() => setEdit({ level })}><Icon name="plus" className="size-4" />{t('level_subjects.add')}</SecondaryButton>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {edit && options.data && (
        <LevelSubjectDialog termId={options.data.term.id} level={edit.level} row={edit.row} subjects={options.data.subjects} teachers={options.data.teachers}
          taken={(q.data?.data ?? []).filter((r) => r.level.id === edit.level.id).map((r) => r.subject.id)}
          onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function LevelSubjectDialog({ termId, level, row, subjects, teachers, taken, onClose, onSaved }: {
  termId: number; level: Ref; row?: LevelSubject; subjects: Ref[]; teachers: Ref[]; taken: number[]; onClose: () => void; onSaved: (m: string) => void
}) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const free = subjects.filter((s) => s.id === row?.subject.id || !taken.includes(s.id))
  const [form, setForm] = useState({
    subject_id: row?.subject.id ?? free[0]?.id ?? 0, teacher_id: row?.teacher?.id ?? null as number | null,
    weekly_sessions: row?.weekly_sessions ?? null as number | null, notes: row?.notes ?? '', sort: row?.sort ?? 0,
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, notes: form.notes || null }
      return row ? termSetupApi.updateLevelSubject(row.id, d) : termSetupApi.createLevelSubject({ ...d, academic_term_id: termId, level_id: level.id })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['term-setup-level-subjects'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })

  return (
    <Modal title={row ? t('level_subjects.title_edit', { level: level.name }) : t('level_subjects.title_new', { level: level.name })} onClose={onClose}
      footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <Field error={errors.subject_id?.[0]}>
        <SelectField label={t('fields.subject')} value={String(form.subject_id || '')} disabled={!!row} onChange={(e) => setForm({ ...form, subject_id: Number(e.target.value) })}
          options={free.map((s) => ({ value: String(s.id), label: s.name }))} />
      </Field>
      <Field error={errors.teacher_id?.[0]}>
        <SelectField label={t('fields.teacher')} value={String(form.teacher_id ?? '')} onChange={(e) => setForm({ ...form, teacher_id: e.target.value ? Number(e.target.value) : null })}
          options={[{ value: '', label: t('no_teacher') }, ...teachers.map((x) => ({ value: String(x.id), label: x.name }))]} />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.weekly_sessions?.[0]}>
          <TextInput label={t('fields.weekly_sessions')} type="number" min={1} max={20} value={form.weekly_sessions ?? ''} onChange={(e) => setForm({ ...form, weekly_sessions: e.target.value ? Number(e.target.value) : null })} />
        </Field>
        <Field error={errors.sort?.[0]}>
          <TextInput label={t('fields.sort')} type="number" min={0} max={999} value={form.sort} onChange={(e) => setForm({ ...form, sort: Number(e.target.value) })} />
        </Field>
      </div>
      <TextArea label={t('fields.notes')} rows={2} dir="auto" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
    </Modal>
  )
}
