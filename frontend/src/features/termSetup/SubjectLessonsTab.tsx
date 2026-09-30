import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { termSetupApi, type SubjectLesson, type SubjectLessonInput, type SubjectLessonPreview } from '../../api/termSetup'
import SelectField from '../../components/SelectField'
import Icon from '../../components/Icon'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, PrimaryButton, SecondaryButton, SURFACE, TABLE_HEAD, TableWrap, TextArea, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { PreviewCounts, SheetPicker } from '../common/SheetImport'
import { useCanManage, useSetupOptions } from './shared'

/** دروس المواد: a subject's curriculum, for one level or for every level. Not tied to a term. */
export default function SubjectLessonsTab() {
  const { t, i18n } = useTranslation('termSetup')
  const canManage = useCanManage()
  const options = useSetupOptions()
  const subjects = options.data?.subjects ?? []
  const levels = options.data?.levels ?? []
  const [subjectId, setSubjectId] = useState<number>(() => subjects[0]?.id ?? 0)
  const [levelId, setLevelId] = useState<number | ''>('')
  const q = useQuery({
    queryKey: ['term-setup-subject-lessons', subjectId, levelId],
    queryFn: () => termSetupApi.subjectLessons({ subject_id: subjectId, level_id: levelId || undefined }),
    enabled: subjectId > 0, placeholderData: keepPreviousData,
  })
  const [edit, setEdit] = useState<SubjectLesson | 'new' | null>(null)
  const [importing, setImporting] = useState(false)
  const { notice, setNotice, remove } = useRemove(termSetupApi.removeSubjectLesson, [['term-setup-subject-lessons']], t('subject_lessons.delete_confirm'))

  return (
    <div className="space-y-4">
      <FilterBar>
        <SelectField label={t('fields.subject')} hideLabel className="sm:w-56" value={String(subjectId)} onChange={(e) => setSubjectId(Number(e.target.value))}
          options={subjects.map((s) => ({ value: String(s.id), label: s.name }))} />
        <SelectField label={t('fields.level')} hideLabel className="sm:w-56" value={String(levelId)} onChange={(e) => setLevelId(e.target.value ? Number(e.target.value) : '')}
          options={[{ value: '', label: t('subject_lessons.all_levels_filter') }, ...levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
      </FilterBar>
      <Toolbar label={canManage ? t('subject_lessons.new') : undefined} onAdd={canManage ? () => setEdit('new') : undefined} notice={notice}>
        {canManage && <SecondaryButton onClick={() => setImporting(true)}><Icon name="table" className="size-4" />{t('subject_lessons.import')}</SecondaryButton>}
      </Toolbar>
      <p className="text-sm text-ink/60">{t('subject_lessons.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.length ? (
        <EmptyCard icon="evaluation" title={t('subject_lessons.empty')} />
      ) : (
        <ol className={`${SURFACE} divide-y divide-ink/6`}>
          {q.data.map((l, i) => (
            <li key={l.id} className={`flex items-start gap-3 px-4 py-3 ${l.is_active ? '' : 'opacity-60'}`}>
              <span className="mt-0.5 w-7 shrink-0 text-sm tabular-nums text-ink/50">{formatNumber(i + 1, i18n.language)}</span>
              <div className="min-w-0 flex-1">
                <p dir="auto" className="font-medium text-ink">{l.title}</p>
                {l.description && <p dir="auto" className="mt-0.5 text-sm text-ink/55">{l.description}</p>}
              </div>
              <Badge tone={l.level ? 'info' : 'muted'}>{l.level?.name ?? t('subject_lessons.all_levels')}</Badge>
              {!l.is_active && <Badge tone="muted">{t('inactive')}</Badge>}
              {canManage && (
                <div className="flex shrink-0">
                  <IconButton icon="edit" label={t('edit')} onClick={() => setEdit(l)} />
                  <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(l.id)} />
                </div>
              )}
            </li>
          ))}
        </ol>
      )}
      {importing && <ImportDialog onClose={() => setImporting(false)} onDone={(m) => { setImporting(false); setNotice({ tone: 'success', text: m }) }} />}
      {edit && (
        <SubjectLessonDialog row={edit === 'new' ? undefined : edit} subjectId={subjectId} levelId={levelId || null}
          onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function SubjectLessonDialog({ row, subjectId, levelId, onClose, onSaved }: { row?: SubjectLesson; subjectId: number; levelId: number | null; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('termSetup')
  const qc = useQueryClient()
  const options = useSetupOptions()
  const [form, setForm] = useState<SubjectLessonInput>(() => ({
    subject_id: row?.subject.id ?? subjectId, level_id: row ? row.level?.id ?? null : levelId,
    title: row?.title ?? '', description: row?.description ?? '', sort: row?.sort ?? 0, is_active: row?.is_active ?? true,
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, description: form.description || null }
      return row ? termSetupApi.updateSubjectLesson(row.id, d) : termSetupApi.createSubjectLesson(d)
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['term-setup-subject-lessons'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })

  return (
    <Modal title={row ? t('subject_lessons.title_edit') : t('subject_lessons.title_new')} onClose={onClose}
      footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('fields.subject')} value={String(form.subject_id)} onChange={(e) => setForm({ ...form, subject_id: Number(e.target.value) })}
          options={(options.data?.subjects ?? []).map((s) => ({ value: String(s.id), label: s.name }))} />
        <SelectField label={t('fields.level')} value={String(form.level_id ?? '')} onChange={(e) => setForm({ ...form, level_id: e.target.value ? Number(e.target.value) : null })}
          options={[{ value: '', label: t('subject_lessons.all_levels') }, ...(options.data?.levels ?? []).map((l) => ({ value: String(l.id), label: l.name }))]} />
      </div>
      <Field error={errors.title?.[0]}><TextInput label={t('fields.title')} dir="auto" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
      <TextArea label={t('fields.description')} rows={2} dir="auto" value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.sort?.[0]}><TextInput label={t('fields.sort')} type="number" min={0} value={form.sort} onChange={(e) => setForm({ ...form, sort: Number(e.target.value) })} /></Field>
        <label className="flex items-center gap-2 self-end pb-2 text-sm text-ink/80">
          <input type="checkbox" className="size-4 accent-brand-700" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
          {t('fields.is_active')}
        </label>
      </div>
    </Modal>
  )
}

/** Excel import of دروس المواد: template, preview with per-row errors, then create the valid rows. */
function ImportDialog({ onClose, onDone }: { onClose: () => void; onDone: (m: string) => void }) {
  const { t, i18n } = useTranslation('termSetup')
  const { t: tc } = useTranslation('common')
  const qc = useQueryClient()
  const [preview, setPreview] = useState<SubjectLessonPreview | null>(null)
  const [error, setError] = useState<string | null>(null)
  const commit = useMutation({
    mutationFn: () => termSetupApi.subjectLessonsImport(preview!.rows.filter((r) => !Object.keys(r.errors).length).map(({ row, data }) => ({ row, data }))),
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['term-setup-subject-lessons'] }); onDone(r.message) },
    onError: (e) => setError(parseApiError(e).message),
  })

  return (
    <Modal wide title={t('subject_lessons.import')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('subject_lessons.close')}</SecondaryButton>
        <PrimaryButton disabled={!preview || preview.valid === 0} loading={commit.isPending} onClick={() => commit.mutate()}>{t('subject_lessons.import_run', { count: preview?.valid ?? 0 })}</PrimaryButton></>}>
      <p className="text-sm text-ink/65">{t('subject_lessons.import_hint')}</p>
      <SheetPicker template={termSetupApi.subjectLessonsTemplate} templateName="subject-lessons-template.xlsx" onFile={async (f) => { setError(null); setPreview(await termSetupApi.subjectLessonsPreview(f)) }} />
      {error && <p className="text-sm text-danger">{error}</p>}
      {preview && (
        <div className="space-y-3">
          <PreviewCounts valid={preview.valid} invalid={preview.invalid} />
          <TableWrap surface>
            <table className="w-full min-w-[36rem] text-sm">
              <thead className={TABLE_HEAD}>
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{tc('sheet_import.row')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('fields.subject')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('fields.level')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('fields.title')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{tc('sheet_import.status')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-ink/6">
                {preview.rows.map((r) => {
                  const errs = Object.values(r.errors)
                  return (
                    <tr key={r.row} className={errs.length ? 'bg-danger/5' : ''}>
                      <td className="px-3 py-2 tabular-nums text-ink/60">{formatNumber(r.row, i18n.language)}</td>
                      <td className="px-3 py-2" dir="auto">{r.data.subject_name ?? r.data.subject ?? ''}</td>
                      <td className="px-3 py-2" dir="auto">{r.data.level_name ?? r.data.level ?? t('subject_lessons.all_levels')}</td>
                      <td className="px-3 py-2 font-medium text-ink" dir="auto">{r.data.title ?? ''}</td>
                      <td className="px-3 py-2">{errs.length ? <span className="text-danger">{errs.join('، ')}</span> : <span className="text-brand-700">{tc('sheet_import.ok')}</span>}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </TableWrap>
        </div>
      )}
    </Modal>
  )
}
