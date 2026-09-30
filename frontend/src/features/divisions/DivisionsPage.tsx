import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { divisionsApi, type Division, type DivisionStudent, type DivisionsPayload } from '../../api/education'
import { parseApiError, type FieldErrors } from '../../api/client'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, IconButton, LoadingState, Modal, Notice, SecondaryButton, SURFACE, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import { DialogFooter, Field, Toolbar, useRemove } from '../common/crud'
import { useTermScope } from '../../app/term'

/** التقسيمات: pick a class of the term, then create, edit and delete its divisions and assign its students. */
export default function DivisionsPage() {
  const { t, i18n } = useTranslation('divisions')
  const ts = useTermScope()
  const locale = i18n.language
  const [lessonId, setLessonId] = useState<number | ''>('')
  const base = useQuery({ queryKey: ['divisions', 'classes'], queryFn: () => divisionsApi.list() })
  const q = useQuery({ queryKey: ['divisions', lessonId], queryFn: () => divisionsApi.list(Number(lessonId)), enabled: lessonId !== '' })
  const [edit, setEdit] = useState<Division | 'new' | null>(null)
  const [assign, setAssign] = useState<Division | null>(null)
  const { notice, setNotice, remove } = useRemove(divisionsApi.remove, [['divisions']], t('delete_confirm'))

  const data = q.data
  const unassigned = (data?.students ?? []).filter((s) => !s.division_id).length

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:menu.divisions')} subtitle={base.data ? t('subtitle', { term: ts.label(base.data.term.name) }) : undefined} />
      </div>
      {base.isLoading ? <LoadingState /> : base.isError ? (
        isAxiosError(base.error) && base.error.response?.status === 422 ? <Notice tone="info">{parseApiError(base.error).message}</Notice> : <ErrorState onRetry={() => void base.refetch()} />
      ) : base.data && (base.data.classes.length === 0 ? <EmptyCard icon="lessons" title={t('no_classes', { scope: ts.scope() })} /> : (
        <>
          <FilterBar label={t('class')}>
            <SelectField label={t('class')} hideLabel className="sm:w-72" value={String(lessonId)} onChange={(e) => setLessonId(e.target.value ? Number(e.target.value) : '')}
              options={[{ value: '', label: t('pick_class') }, ...base.data.classes.map((c) => ({ value: String(c.id), label: c.name }))]} />
          </FilterBar>
          {lessonId === '' ? <EmptyCard icon="students" title={t('pick_class')} /> : q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : data && (
            <div className="space-y-4">
              <Toolbar label={data.can_manage ? t('new') : undefined} onAdd={data.can_manage ? () => setEdit('new') : undefined} notice={notice} />
              {!data.can_manage && <Notice tone="info">{t('read_only')}</Notice>}
              {(data.students?.length ?? 0) > 0 && <p className="text-sm text-ink/60">{t('unassigned', { n: formatNumber(unassigned, locale) })}</p>}
              {(data.divisions ?? []).length === 0 ? <EmptyCard icon="students" title={t('empty')} /> : (
                <div className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
                  {data.divisions!.map((d) => {
                    const members = (data.students ?? []).filter((s) => d.student_ids.includes(s.id))
                    return (
                      <article key={d.id} className={`${SURFACE} space-y-3 p-4`}>
                        <div className="flex items-start gap-2">
                          <div className="min-w-0 flex-1">
                            <h3 dir="auto" className="font-semibold text-ink">{d.name}</h3>
                            <p className="text-xs text-ink/55">{d.teacher ? <bdi>{d.teacher.name}</bdi> : t('no_teacher')}</p>
                          </div>
                          <Badge tone="brand">{t('students_count', { count: members.length, n: formatNumber(members.length, locale) })}</Badge>
                          {data.can_manage && (
                            <div className="flex shrink-0">
                              <IconButton icon="edit" label={t('edit')} onClick={() => setEdit(d)} />
                              <IconButton icon="trash" tone="danger" label={t('delete')} onClick={() => remove(d.id)} />
                            </div>
                          )}
                        </div>
                        {members.length > 0 && (
                          <ul className="flex flex-wrap gap-1.5">
                            {members.map((s) => <li key={s.id}><Badge><bdi>{s.full_name}</bdi></Badge></li>)}
                          </ul>
                        )}
                        {data.can_manage && <SecondaryButton onClick={() => setAssign(d)}>{t('assign')}</SecondaryButton>}
                      </article>
                    )
                  })}
                </div>
              )}
            </div>
          )}
        </>
      ))}
      {edit && data && base.data && (
        <DivisionDialog row={edit === 'new' ? undefined : edit} data={data} termId={base.data.term.id} lessonId={Number(lessonId)}
          onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
      {assign && data && (
        <AssignDialog division={assign} students={data.students ?? []} divisions={data.divisions ?? []}
          onClose={() => setAssign(null)} onSaved={(m) => { setAssign(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function DivisionDialog({ row, data, termId, lessonId, onClose, onSaved }: { row?: Division; data: DivisionsPayload; termId: number; lessonId: number; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('divisions')
  const qc = useQueryClient()
  const [name, setName] = useState(row?.name ?? '')
  const [teacherId, setTeacherId] = useState<string>(row?.teacher ? String(row.teacher.id) : '')
  const [sort, setSort] = useState(row ? String(row.sort) : '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { name, teacher_id: teacherId ? Number(teacherId) : null, sort: sort === '' ? null : Number(sort) }
      return row ? divisionsApi.update(row.id, d) : divisionsApi.create({ ...d, academic_term_id: termId, lesson_id: lessonId })
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['divisions'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  return (
    <Modal title={row ? t('title_edit') : t('title_new')} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      <Field error={errors.name?.[0]}><TextInput label={t('name')} dir="auto" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label={t('teacher')} value={teacherId} onChange={(e) => setTeacherId(e.target.value)} error={errors.teacher_id?.[0]}
          options={[{ value: '', label: t('no_teacher') }, ...data.teachers.map((u) => ({ value: String(u.id), label: u.name }))]} />
        <Field error={errors.sort?.[0]}><TextInput label={t('sort')} inputMode="numeric" dir="ltr" value={sort} onChange={(e) => setSort(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

function AssignDialog({ division, students, divisions, onClose, onSaved }: { division: Division; students: DivisionStudent[]; divisions: Division[]; onClose: () => void; onSaved: (m: string) => void }) {
  const { t } = useTranslation('divisions')
  const qc = useQueryClient()
  const [picked, setPicked] = useState<number[]>(division.student_ids)
  const [error, setError] = useState<string | null>(null)
  const names = new Map(divisions.map((d) => [d.id, d.name]))
  const save = useMutation({
    mutationFn: () => divisionsApi.assign(division.id, picked),
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['divisions'] }); onSaved(r.message) },
    onError: (e) => { const p = parseApiError(e); setError(Object.values(p.fields)[0]?.[0] ?? p.message) },
  })
  return (
    <Modal title={t('assign_title', { name: division.name })} onClose={onClose} footer={<DialogFooter onCancel={onClose} onSave={() => save.mutate()} saving={save.isPending} />}>
      {error && <Notice tone="error">{error}</Notice>}
      {students.length === 0 ? <p className="text-sm text-ink/60">{t('no_students')}</p> : (
        <ul className="divide-y divide-ink/6">
          {students.map((s) => {
            const other = s.division_id && s.division_id !== division.id ? names.get(s.division_id) : null
            return (
              <li key={s.id}>
                <label className={`flex items-center gap-3 py-2 text-sm ${other ? 'text-ink/45' : 'text-ink'}`}>
                  <input type="checkbox" className="size-4 accent-brand-700" disabled={!!other} checked={picked.includes(s.id)}
                    onChange={() => setPicked((ps) => (ps.includes(s.id) ? ps.filter((x) => x !== s.id) : [...ps, s.id]))} />
                  <span dir="auto" className="min-w-0 flex-1 truncate">{s.full_name}</span>
                  {other && <span className="text-xs">{t('in_other', { name: other })}</span>}
                </label>
              </li>
            )
          })}
        </ul>
      )}
    </Modal>
  )
}
