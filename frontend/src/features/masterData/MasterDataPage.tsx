import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { parseApiError, type FieldErrors } from '../../api/client'
import { levelsApi, subjectsApi, termsApi, type AcademicTerm, type Level, type LevelInput, type Subject, type SubjectInput, type TermInput } from '../../api/masterData'
import { useAuth } from '../../app/AuthContext'
import Icon from '../../components/Icon'
import { PageBand } from '../../components/ornaments'
import { Badge, EmptyCard, ErrorState, LoadingState, Modal, Notice, PrimaryButton, SecondaryButton, Segmented, SURFACE, TextArea, TextInput } from '../../components/ui'
import { formatDate, formatNumber } from '../../lib/format'

type Tab = 'terms' | 'levels' | 'subjects'
const TABS: { key: Tab; permission: string }[] = [
  { key: 'terms', permission: 'terms.manage' },
  { key: 'levels', permission: 'levels.manage' },
  { key: 'subjects', permission: 'subjects.manage' },
]

/** القوائم: academic terms, levels and subjects. Each tab shows only for the permission that manages it. */
export default function MasterDataPage() {
  const { t } = useTranslation('masterData')
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabs = TABS.filter((x) => can(x.permission))
  const tab = tabs.find((x) => x.key === params.get('tab'))?.key ?? tabs[0]?.key ?? 'terms'

  return (
    <div className="space-y-5">
      <div className="hidden lg:block">
        <PageBand title={t('title')} subtitle={t('subtitle')} />
      </div>
      {tabs.length > 1 && (
        <div className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0">
          <Segmented name="master-data-tab" label={t('title')} value={tab}
            options={tabs.map((x) => ({ value: x.key, label: t(`tabs.${x.key}`) }))}
            onChange={(v) => setParams({ tab: v }, { replace: true })} />
        </div>
      )}
      {tab === 'terms' && <Terms />}
      {tab === 'levels' && <Levels />}
      {tab === 'subjects' && <Subjects />}
    </div>
  )
}

/** Delete with confirmation; the server's refusal (in use, current, system) shows as a notice. */
function useRemove(fn: (id: number) => Promise<{ message: string }>, keys: string[][], confirmText: string) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const m = useMutation({
    mutationFn: fn,
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); keys.forEach((k) => void qc.invalidateQueries({ queryKey: k })) },
    onError: (e) => { const p = parseApiError(e); setNotice({ tone: 'error', text: Object.values(p.fields)[0]?.[0] ?? p.message }) },
  })
  return { notice, setNotice, remove: (id: number) => { if (window.confirm(confirmText)) m.mutate(id) } }
}

function Toolbar({ label, onAdd, notice }: { label: string; onAdd: () => void; notice: { tone: 'success' | 'error'; text: string } | null }) {
  return (
    <>
      <div className="flex justify-end"><PrimaryButton onClick={onAdd}><Icon name="plus" className="size-4" />{label}</PrimaryButton></div>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </>
  )
}

function ItemActions({ onEdit, onDelete, canDelete = true, children }: { onEdit: () => void; onDelete: () => void; canDelete?: boolean; children?: React.ReactNode }) {
  const { t } = useTranslation('masterData')
  return (
    <div className="mt-3 flex flex-wrap gap-2">
      <SecondaryButton onClick={onEdit}><Icon name="edit" className="size-4" />{t('edit')}</SecondaryButton>
      {children}
      {canDelete && <SecondaryButton onClick={onDelete} className="text-danger"><Icon name="trash" className="size-4" />{t('delete')}</SecondaryButton>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Academic terms
// ---------------------------------------------------------------------------

function Terms() {
  const { t, i18n } = useTranslation('masterData')
  const locale = i18n.language
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['academic-terms'], queryFn: termsApi.list, staleTime: 5 * 60_000 })
  const [edit, setEdit] = useState<AcademicTerm | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(termsApi.remove, [['academic-terms']], t('terms.delete_confirm'))
  const current = useMutation({
    mutationFn: termsApi.makeCurrent,
    onSuccess: (r) => { setNotice({ tone: 'success', text: r.message }); void qc.invalidateQueries({ queryKey: ['academic-terms'] }) },
    onError: (e) => setNotice({ tone: 'error', text: parseApiError(e).message }),
  })
  const date = (d: string | null) => (d ? formatDate(d, locale, { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

  return (
    <div className="space-y-4">
      <Toolbar label={t('terms.new')} onAdd={() => setEdit('new')} notice={notice} />
      <p className="text-sm text-ink/60">{t('terms.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.data.length ? (
        <EmptyCard icon="history" title={t('terms.empty')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {q.data.data.map((x) => (
            <li key={x.id} className={`${SURFACE} p-4 ${x.is_current ? 'border-brand-600/40' : ''}`}>
              <div className="flex items-start justify-between gap-2">
                <p dir="auto" className="font-semibold text-ink">{x.name}</p>
                {x.is_current && <Badge tone="brand">{t('terms.current')}</Badge>}
              </div>
              <p className="mt-1 text-sm text-ink/60">
                {x.academic_year && <span className="tabular-nums">{x.academic_year} · </span>}
                <span className="tabular-nums">{date(x.start_date)} – {date(x.end_date)}</span>
              </p>
              <p className="mt-1 text-sm text-ink/55">
                {t('terms.counts', { packages: formatNumber(x.packages_count ?? 0, locale), invoices: formatNumber(x.invoices_count ?? 0, locale) })}
              </p>
              {x.legacy_label && <p className="mt-1 text-xs text-ink/50">{t('terms.migrated', { label: x.legacy_label })}</p>}
              <ItemActions onEdit={() => setEdit(x)} onDelete={() => remove(x.id)} canDelete={!x.is_current}>
                {!x.is_current && <SecondaryButton onClick={() => current.mutate(x.id)} disabled={current.isPending}><Icon name="check" className="size-4" />{t('terms.make_current')}</SecondaryButton>}
              </ItemActions>
            </li>
          ))}
        </ul>
      )}
      {edit && <TermDialog term={edit === 'new' ? undefined : edit} onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />}
    </div>
  )
}

function TermDialog({ term, onClose, onSaved }: { term?: AcademicTerm; onClose: () => void; onSaved: (message: string) => void }) {
  const { t } = useTranslation('masterData')
  const qc = useQueryClient()
  const [form, setForm] = useState<TermInput>(() => ({
    name_ar: term?.name_ar ?? '', name_en: term?.name_en ?? '', academic_year: term?.academic_year ?? '', start_date: term?.start_date ?? '', end_date: term?.end_date ?? '',
    ...(term ? {} : { is_current: false }),
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => {
      const d = { ...form, academic_year: form.academic_year || null, start_date: form.start_date || null, end_date: form.end_date || null }
      return term ? termsApi.update(term.id, d) : termsApi.create(d)
    },
    onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['academic-terms'] }); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  const set = <K extends keyof TermInput>(k: K, v: TermInput[K]) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <Modal title={term ? t('terms.title_edit') : t('terms.title_new')} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('save')}</PrimaryButton></>}>
      <Field error={errors.name_ar?.[0]}><TextInput label={t('fields.name_ar')} dir="rtl" placeholder={t('terms.name_placeholder')} value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} /></Field>
      <Field error={errors.name_en?.[0]}><TextInput label={t('fields.name_en')} dir="ltr" value={form.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
      <Field error={errors.academic_year?.[0]}><TextInput label={t('fields.academic_year')} dir="ltr" inputMode="numeric" placeholder="2026/2027" value={form.academic_year ?? ''} onChange={(e) => set('academic_year', e.target.value)} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.start_date?.[0]}><TextInput label={t('fields.start_date')} type="date" value={form.start_date ?? ''} onChange={(e) => set('start_date', e.target.value)} /></Field>
        <Field error={errors.end_date?.[0]}><TextInput label={t('fields.end_date')} type="date" value={form.end_date ?? ''} onChange={(e) => set('end_date', e.target.value)} /></Field>
      </div>
      {!term && (
        <label className="flex items-center gap-2 text-sm text-ink/80">
          <input type="checkbox" className="size-4 accent-brand-700" checked={!!form.is_current} onChange={(e) => set('is_current', e.target.checked)} />
          {t('terms.set_current')}
        </label>
      )}
      {errors.is_current?.[0] && <Notice tone="error">{errors.is_current[0]}</Notice>}
    </Modal>
  )
}

function Field({ error, children }: { error?: string; children: React.ReactNode }) {
  return (
    <div>
      {children}
      {error && <p className="mt-1 text-sm text-danger">{error}</p>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Levels and subjects share one form: Arabic and English names, code, order, description, active.
// ---------------------------------------------------------------------------

type Named = LevelInput | SubjectInput

function NamedDialog<T extends Named>({ kind, item, locked = false, save: saveFn, invalidate, onClose, onSaved }: {
  kind: 'levels' | 'subjects'
  item?: T & { id: number }
  /** System subject (Quran): the code and the active switch cannot change. */
  locked?: boolean
  save: (d: Named) => Promise<{ message: string }>
  invalidate: string[][]
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const { t } = useTranslation('masterData')
  const qc = useQueryClient()
  const [form, setForm] = useState<Named>(() => ({
    name_ar: item?.name_ar ?? '', name_en: item?.name_en ?? '', code: item?.code ?? '', description: item?.description ?? '', sort: item?.sort ?? 0, is_active: item?.is_active ?? true,
  }))
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = useMutation({
    mutationFn: () => saveFn({ ...form, code: form.code || null, description: form.description || null }),
    onSuccess: (r) => { invalidate.forEach((k) => void qc.invalidateQueries({ queryKey: k })); onSaved(r.message) },
    onError: (e) => setErrors(parseApiError(e).fields),
  })
  const set = <K extends keyof Named>(k: K, v: Named[K]) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <Modal title={t(item ? `${kind}.title_edit` : `${kind}.title_new`)} onClose={onClose}
      footer={<><SecondaryButton onClick={onClose}>{t('cancel')}</SecondaryButton><PrimaryButton loading={save.isPending} onClick={() => save.mutate()}>{t('save')}</PrimaryButton></>}>
      <Field error={errors.name_ar?.[0]}><TextInput label={t('fields.name_ar')} dir="rtl" placeholder={t(`${kind}.name_placeholder`)} value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} /></Field>
      <Field error={errors.name_en?.[0]}><TextInput label={t('fields.name_en')} dir="ltr" value={form.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field error={errors.code?.[0]}><TextInput label={t('fields.code')} dir="ltr" disabled={locked} value={form.code ?? ''} onChange={(e) => set('code', e.target.value)} /></Field>
        <Field error={errors.sort?.[0]}><TextInput label={t('fields.sort')} type="number" min={0} max={999} value={form.sort} onChange={(e) => set('sort', Number(e.target.value))} /></Field>
      </div>
      <TextArea label={t('fields.description')} rows={2} dir="auto" value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} />
      <label className="flex items-center gap-2 text-sm text-ink/80">
        <input type="checkbox" className="size-4 accent-brand-700" disabled={locked} checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
        {t('fields.is_active')}
      </label>
      {locked && <p className="text-xs text-ink/55">{t('subjects.system_hint')}</p>}
      {(errors.is_active?.[0]) && <Notice tone="error">{errors.is_active[0]}</Notice>}
    </Modal>
  )
}

function Levels() {
  const { t, i18n } = useTranslation('masterData')
  const q = useQuery({ queryKey: ['levels'], queryFn: () => levelsApi.list() })
  const [edit, setEdit] = useState<Level | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(levelsApi.remove, [['levels'], ['level-options']], t('levels.delete_confirm'))

  return (
    <div className="space-y-4">
      <Toolbar label={t('levels.new')} onAdd={() => setEdit('new')} notice={notice} />
      <p className="text-sm text-ink/60">{t('levels.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.length ? (
        <EmptyCard icon="lessons" title={t('levels.empty')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {q.data.map((x) => (
            <li key={x.id} className={`${SURFACE} p-4 ${x.is_active ? '' : 'border-dashed opacity-75'}`}>
              <div className="flex items-start justify-between gap-2">
                <p dir="auto" className="font-semibold text-ink">{x.name}</p>
                {!x.is_active && <Badge tone="muted">{t('inactive')}</Badge>}
              </div>
              <p className="mt-1 text-sm text-ink/60">
                {x.code && <span className="tabular-nums" dir="ltr">{x.code} · </span>}
                {t('levels.circles', { n: formatNumber(x.lessons_count ?? 0, i18n.language) })}
              </p>
              {x.description && <p dir="auto" className="mt-1 text-sm text-ink/55">{x.description}</p>}
              <ItemActions onEdit={() => setEdit(x)} onDelete={() => remove(x.id)} />
            </li>
          ))}
        </ul>
      )}
      {edit && (
        <NamedDialog kind="levels" item={edit === 'new' ? undefined : edit} invalidate={[['levels'], ['level-options']]}
          save={(d) => (edit === 'new' ? levelsApi.create(d) : levelsApi.update(edit.id, d))}
          onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}

function Subjects() {
  const { t } = useTranslation('masterData')
  const q = useQuery({ queryKey: ['subjects'], queryFn: () => subjectsApi.list() })
  const [edit, setEdit] = useState<Subject | 'new' | null>(null)
  const { notice, setNotice, remove } = useRemove(subjectsApi.remove, [['subjects']], t('subjects.delete_confirm'))

  return (
    <div className="space-y-4">
      <Toolbar label={t('subjects.new')} onAdd={() => setEdit('new')} notice={notice} />
      <p className="text-sm text-ink/60">{t('subjects.hint')}</p>
      {q.isLoading ? <LoadingState /> : q.isError ? <ErrorState onRetry={() => void q.refetch()} /> : !q.data?.length ? (
        <EmptyCard icon="evaluation" title={t('subjects.empty')} />
      ) : (
        <ul className="grid gap-3 *:min-w-0 md:grid-cols-2 xl:grid-cols-3">
          {q.data.map((x) => (
            <li key={x.id} className={`${SURFACE} p-4 ${x.is_active ? '' : 'border-dashed opacity-75'}`}>
              <div className="flex items-start justify-between gap-2">
                <p dir="auto" className="font-semibold text-ink">{x.name}</p>
                <div className="flex shrink-0 gap-1.5">
                  {x.is_system && <Badge tone="gold">{t('subjects.system')}</Badge>}
                  {!x.is_active && <Badge tone="muted">{t('inactive')}</Badge>}
                </div>
              </div>
              {x.code && <p className="mt-1 text-sm tabular-nums text-ink/60" dir="ltr">{x.code}</p>}
              {x.description && <p dir="auto" className="mt-1 text-sm text-ink/55">{x.description}</p>}
              <ItemActions onEdit={() => setEdit(x)} onDelete={() => remove(x.id)} canDelete={!x.is_system} />
            </li>
          ))}
        </ul>
      )}
      {edit && (
        <NamedDialog kind="subjects" item={edit === 'new' ? undefined : edit} locked={edit !== 'new' && edit.is_system} invalidate={[['subjects']]}
          save={(d) => (edit === 'new' ? subjectsApi.create(d) : subjectsApi.update(edit.id, d))}
          onClose={() => setEdit(null)} onSaved={(m) => { setEdit(null); setNotice({ tone: 'success', text: m }) }} />
      )}
    </div>
  )
}
