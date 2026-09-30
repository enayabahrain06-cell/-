import { useState } from 'react'
import { isAxiosError } from 'axios'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { notesApi, type NoteOptions } from '../../api/education'
import { parseApiError } from '../../api/client'
import { PageBand } from '../../components/ornaments'
import SelectField from '../../components/SelectField'
import { Badge, EmptyCard, ErrorState, FilterBar, LoadingState, Notice, SearchInput, Segmented, SURFACE, TextInput } from '../../components/ui'
import { formatNumber } from '../../lib/format'
import NoteList from './NoteList'
import { useEmbed, useOwnParam } from '../../app/embed'
import { useTermScope } from '../../app/term'

const TABS = ['students', 'general', 'levels', 'levels_view', 'subjects', 'subjects_view'] as const
type Tab = (typeof TABS)[number]
const TAB_ENTRY: Record<Tab, string> = {
  students: 'student_notes', general: 'general_notes', levels: 'level_notes', levels_view: 'view_level_notes',
  subjects: 'level_subject_notes', subjects_view: 'view_level_subject_notes',
}

/** U9 الملاحظات: one page, one tab per menu entry (students, general, levels and level subjects, with their views). */
export default function NotesPage() {
  const { t } = useTranslation('notes')
  const ts = useTermScope()
  const [params, setParams] = useSearchParams()
  const host = useEmbed()
  const ownTab = useOwnParam(params, 'tab')
  const tab: Tab = (TABS as readonly string[]).includes(ownTab ?? '') ? (ownTab as Tab) : 'students'
  const opts = useQuery({ queryKey: ['note-options'], queryFn: notesApi.options })

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('nav:notes')} subtitle={opts.data ? t('subtitle', { term: ts.label(opts.data.term.name) }) : undefined} />
      </div>
      {!host && <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
        <Segmented name="notes-tab" label={t('nav:notes')} value={tab} onChange={(v) => setParams({ tab: v }, { replace: true })}
          options={TABS.map((k) => ({ value: k, label: t(`nav:menu.${TAB_ENTRY[k]}`) }))} />
      </div>}
      {opts.isLoading ? <LoadingState /> : opts.isError ? (
        isAxiosError(opts.error) && opts.error.response?.status === 422 ? <Notice tone="info">{parseApiError(opts.error).message}</Notice> : <ErrorState onRetry={() => void opts.refetch()} />
      ) : opts.data && (
        <>
          {tab === 'students' && <StudentsTab o={opts.data} />}
          {tab === 'general' && <NoteList filters={{ scope: 'general' }} add={opts.data.can_manage ? { academic_term_id: opts.data.term.id, scope: 'general' } : undefined} />}
          {tab === 'levels' && <TargetTab o={opts.data} kind="level" />}
          {tab === 'subjects' && <TargetTab o={opts.data} kind="level_subject" />}
          {tab === 'levels_view' && <ViewTab o={opts.data} kind="level" />}
          {tab === 'subjects_view' && <ViewTab o={opts.data} kind="level_subject" />}
        </>
      )}
    </div>
  )
}

/** ملاحظات الطلبة: class → its students (with how many notes) → the chosen student's notes and the add form. */
function StudentsTab({ o }: { o: NoteOptions }) {
  const { t, i18n } = useTranslation('notes')
  const ts = useTermScope()
  const locale = i18n.language
  const [lessonId, setLessonId] = useState<number | ''>(o.classes[0]?.id ?? '')
  const [studentId, setStudentId] = useState<number | null>(null)
  const [search, setSearch] = useState('')
  const students = useQuery({ queryKey: ['note-students', lessonId], queryFn: () => notesApi.students(Number(lessonId)), enabled: lessonId !== '' })
  const rows = (students.data?.data ?? []).filter((s) => !search.trim() || s.full_name.includes(search.trim()) || s.student_no.includes(search.trim()))
  const student = students.data?.data.find((s) => s.id === studentId)

  if (o.classes.length === 0) return <EmptyCard icon="lessons" title={t('no_classes', { scope: ts.scope() })} />
  return (
    <div className="grid gap-4 *:min-w-0 lg:grid-cols-[minmax(16rem,22rem)_1fr]">
      <section className={`${SURFACE} space-y-3 p-4`} aria-label={t('students')}>
        <SelectField label={t('class')} value={String(lessonId)} onChange={(e) => { setLessonId(Number(e.target.value)); setStudentId(null) }}
          options={o.classes.map((c) => ({ value: String(c.id), label: c.name }))} />
        <SearchInput label={t('search_student')} placeholder={t('search_student')} value={search} onChange={(e) => setSearch(e.target.value)} />
        {students.isLoading ? <LoadingState /> : students.isError ? <ErrorState onRetry={() => void students.refetch()} /> : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-ink/55">{t('no_students')}</p>
        ) : (
          <ul className="max-h-[28rem] divide-y divide-ink/6 overflow-y-auto">
            {rows.map((s) => (
              <li key={s.id}>
                <button type="button" onClick={() => setStudentId(s.id)} aria-pressed={studentId === s.id}
                  className={`flex w-full items-center gap-2 rounded-lg px-2 py-2 text-start transition ${studentId === s.id ? 'bg-brand-50 text-brand-800' : 'hover:bg-ink/4'}`}>
                  <span className="min-w-0 flex-1">
                    <span dir="auto" className="block truncate text-sm font-medium">{s.full_name}</span>
                    <span className="block text-xs tabular-nums text-ink/50">{s.student_no}</span>
                  </span>
                  {s.notes_count > 0 && <Badge tone="brand">{formatNumber(s.notes_count, locale)}</Badge>}
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
      <div className="space-y-3">
        {student ? (
          <>
            <h2 dir="auto" className="text-base font-semibold text-ink">{student.full_name}</h2>
            <NoteList filters={{ scope: 'student', student_id: student.id }}
              add={o.can_manage ? { academic_term_id: o.term.id, scope: 'student', student_id: student.id, lesson_id: Number(lessonId) } : undefined} />
          </>
        ) : <EmptyCard icon="students" title={t('pick_student')} />}
      </div>
    </div>
  )
}

/** ملاحظات المستويات / ملاحظات مواد المستويات: pick the level (or level subject), add and manage its notes. */
function TargetTab({ o, kind }: { o: NoteOptions; kind: 'level' | 'level_subject' }) {
  const { t } = useTranslation('notes')
  const ts = useTermScope()
  const options = kind === 'level'
    ? o.levels.map((l) => ({ value: String(l.id), label: l.name }))
    : o.level_subjects.map((ls) => ({ value: String(ls.id), label: `${ls.subject.name}، ${ls.level.name}` }))
  const [id, setId] = useState<string>(options[0]?.value ?? '')
  if (options.length === 0) return <EmptyCard icon="edit" title={kind === 'level' ? t('no_levels', { scope: ts.scope() }) : t('no_level_subjects', { scope: ts.scope() })} />
  const target = Number(id)
  return (
    <div className="space-y-4">
      <FilterBar label={t('filters')}>
        <SelectField label={kind === 'level' ? t('level') : t('level_subject')} hideLabel className="sm:w-72" value={id} onChange={(e) => setId(e.target.value)} options={options} />
      </FilterBar>
      <NoteList key={`${kind}-${id}`} filters={kind === 'level' ? { scope: 'level', level_id: target } : { scope: 'level_subject', level_subject_id: target }}
        add={o.can_manage ? { academic_term_id: o.term.id, scope: kind, ...(kind === 'level' ? { level_id: target } : { level_subject_id: target }) } : undefined} />
    </div>
  )
}

/** عرض ملاحظات المستويات / عرض ملاحظات مواد المستويات: read-only, filtered by level, subject, words and dates. */
function ViewTab({ o, kind }: { o: NoteOptions; kind: 'level' | 'level_subject' }) {
  const { t } = useTranslation('notes')
  const [levelId, setLevelId] = useState('')
  const [lsId, setLsId] = useState('')
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [mine, setMine] = useState(false)
  const levelSubjects = o.level_subjects.filter((ls) => !levelId || String(ls.level.id) === levelId)
  return (
    <div className="space-y-4">
      <FilterBar label={t('filters')}>
        <SelectField label={t('level')} hideLabel className="sm:w-52" value={levelId} onChange={(e) => { setLevelId(e.target.value); setLsId('') }}
          options={[{ value: '', label: t('all_levels') }, ...o.levels.map((l) => ({ value: String(l.id), label: l.name }))]} />
        {kind === 'level_subject' && (
          <SelectField label={t('level_subject')} hideLabel className="sm:w-64" value={lsId} onChange={(e) => setLsId(e.target.value)}
            options={[{ value: '', label: t('all_subjects') }, ...levelSubjects.map((ls) => ({ value: String(ls.id), label: `${ls.subject.name}، ${ls.level.name}` }))]} />
        )}
        <SearchInput label={t('search')} placeholder={t('search')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <TextInput label={t('from')} type="date" className="sm:w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        <TextInput label={t('to')} type="date" className="sm:w-40" value={to} onChange={(e) => setTo(e.target.value)} />
        <label className="flex items-center gap-2 text-sm text-ink/80">
          <input type="checkbox" className="size-4 accent-brand-700" checked={mine} onChange={(e) => setMine(e.target.checked)} />
          {t('mine')}
        </label>
      </FilterBar>
      <NoteList readOnly showTarget filters={{
        scope: kind, level_id: levelId ? Number(levelId) : undefined, level_subject_id: lsId ? Number(lsId) : undefined,
        q: search.trim() || undefined, from: from || undefined, to: to || undefined, author: mine ? 'mine' : undefined,
      }} />
    </div>
  )
}
