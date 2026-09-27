import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { parseApiError } from '../../api/client'
import { messagesApi, type MessageTemplate } from '../../api/messages'
import { useAuth } from '../../app/AuthContext'
import { EmptyState } from '../../components/ornaments'
import { Badge, Card, CardTitle, ErrorState, LoadingState, Notice, PrimaryButton, SearchInput, SecondaryButton, Segmented, SURFACE, TextArea, TextInput } from '../../components/ui'
import { formatDate } from '../../lib/format'

type Draft = Pick<MessageTemplate, 'name_ar' | 'name_en' | 'body_ar' | 'body_en' | 'is_active'>

export default function TemplatesTab() {
  const { t, i18n } = useTranslation('messages')
  const locale = i18n.language
  const q = useQuery({ queryKey: ['messages', 'templates', locale], queryFn: messagesApi.templates })
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<number | null>(null)

  const list = useMemo(() => {
    const s = search.trim().toLowerCase()
    return (q.data ?? []).filter((tpl) => !s || [tpl.name, tpl.key, tpl.name_ar, tpl.name_en].some((v) => v?.toLowerCase().includes(s)))
  }, [q.data, search])
  const current = q.data?.find((tpl) => tpl.id === selected) ?? null

  if (q.isLoading) return <LoadingState />
  if (q.isError) return <ErrorState message={t('error')} onRetry={() => void q.refetch()} />
  if (!q.data?.length) return <div className={SURFACE}><EmptyState icon="messages" title={t('templates.empty')} /></div>

  return (
    <div className="grid items-start gap-5 lg:grid-cols-3">
      <section className={`${SURFACE} overflow-hidden`} aria-label={t('tabs.templates')}>
        <div className="border-b border-ink/6 p-3">
          <SearchInput label={t('templates.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <ul className="max-h-[32rem] divide-y divide-ink/6 overflow-y-auto">
          {list.map((tpl) => (
            <li key={tpl.id}>
              <button type="button" onClick={() => setSelected(tpl.id)} aria-current={selected === tpl.id}
                className={`flex w-full items-center gap-2 px-4 py-3 text-start hover:bg-brand-50/40 ${selected === tpl.id ? 'bg-brand-50' : ''}`}>
                <span className="min-w-0 flex-1">
                  <span dir="auto" className="block truncate text-sm font-medium text-ink">{tpl.name}</span>
                  <span dir="ltr" className="block truncate text-start font-mono text-xs text-ink/45">{tpl.key}</span>
                </span>
                {!tpl.is_active && <Badge>{t('templates.inactive')}</Badge>}
              </button>
            </li>
          ))}
        </ul>
      </section>

      <div className="lg:col-span-2">
        {current ? <Editor key={current.id} template={current} locale={locale} /> : (
          <div className={SURFACE}><EmptyState size="sm" icon="edit" title={t('templates.pick')} /></div>
        )}
      </div>
    </div>
  )
}

function Editor({ template, locale }: { template: MessageTemplate; locale: string }) {
  const { t } = useTranslation('messages')
  const { can } = useAuth()
  const qc = useQueryClient()
  const editable = can('messages.manage')
  const initial: Draft = { name_ar: template.name_ar, name_en: template.name_en, body_ar: template.body_ar, body_en: template.body_en, is_active: template.is_active }
  const [draft, setDraft] = useState<Draft>(initial)
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [previewLang, setPreviewLang] = useState<'ar' | 'en'>(locale === 'en' ? 'en' : 'ar')
  const refs = { body_ar: useRef<HTMLTextAreaElement>(null), body_en: useRef<HTMLTextAreaElement>(null) }
  const [lastFocus, setLastFocus] = useState<'body_ar' | 'body_en'>('body_ar')
  const dirty = (Object.keys(initial) as (keyof Draft)[]).some((k) => draft[k] !== initial[k])

  // Live preview of the draft body with sample values, debounced.
  const previewBody = previewLang === 'ar' ? draft.body_ar : draft.body_en
  const [debounced, setDebounced] = useState(previewBody)
  useEffect(() => { const id = setTimeout(() => setDebounced(previewBody), 400); return () => clearTimeout(id) }, [previewBody])
  const preview = useQuery({
    queryKey: ['messages', 'template-preview', template.id, previewLang, debounced],
    queryFn: () => messagesApi.preview(template.id, previewLang, debounced),
  })

  const save = useMutation({
    mutationFn: () => messagesApi.updateTemplate(template.id, draft),
    onSuccess: () => { setFields({}); setNotice({ tone: 'success', text: t('templates.saved') }); void qc.invalidateQueries({ queryKey: ['messages', 'templates'] }) },
    onError: (e) => { const err = parseApiError(e); setFields(err.fields); setNotice({ tone: 'error', text: err.message }) },
  })

  const insert = (v: string) => {
    const key = lastFocus
    const el = refs[key].current
    const token = `{${v}}`
    const text = draft[key]
    const start = el?.selectionStart ?? text.length
    const end = el?.selectionEnd ?? text.length
    setDraft({ ...draft, [key]: text.slice(0, start) + token + text.slice(end) })
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + token.length, start + token.length) })
  }
  const err = (k: string) => fields[k]?.[0]

  return (
    <Card className="space-y-4">
      <CardTitle actions={
        <label className={`inline-flex items-center gap-2 text-sm ${editable ? 'cursor-pointer' : ''}`}>
          <input type="checkbox" className="size-4 accent-brand-600" checked={draft.is_active} disabled={!editable}
            onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })} />
          {draft.is_active ? t('templates.active') : t('templates.inactive')}
        </label>
      }>
        <span dir="auto">{template.name}</span>
      </CardTitle>
      <p className="-mt-2 text-xs text-ink/50">
        <span dir="ltr" className="font-mono">{template.key}</span>
        {template.updated_at && <> · {t('templates.updated', { date: formatDate(template.updated_at, locale, { day: 'numeric', month: 'short', year: 'numeric' }) })}</>}
      </p>
      {!editable && <Notice tone="info">{t('templates.read_only')}</Notice>}

      <div className="grid gap-3 sm:grid-cols-2">
        <TextInput label={t('templates.name_ar')} dir="rtl" value={draft.name_ar} readOnly={!editable} onChange={(e) => setDraft({ ...draft, name_ar: e.target.value })} />
        <TextInput label={t('templates.name_en')} dir="ltr" value={draft.name_en} readOnly={!editable} onChange={(e) => setDraft({ ...draft, name_en: e.target.value })} />
      </div>

      {template.variables.length > 0 && (
        <div>
          <p className="mb-1.5 text-sm font-medium text-ink/75">{t('templates.variables')}</p>
          <div className="flex flex-wrap gap-1.5">
            {template.variables.map((v) => (
              <button key={v} type="button" disabled={!editable} onClick={() => insert(v)}
                className="rounded-lg border border-ink/10 bg-page px-2 py-1 font-mono text-xs text-ink/75 hover:border-brand-500/40 hover:bg-brand-50 disabled:cursor-default disabled:hover:bg-page" dir="ltr">
                {`{${v}}`}
              </button>
            ))}
          </div>
          {editable && <p className="mt-1 text-xs text-ink/50">{t('templates.variables_hint')}</p>}
        </div>
      )}

      {(['body_ar', 'body_en'] as const).map((k) => (
        <div key={k}>
          <TextArea ref={refs[k]} label={t(`templates.${k}`)} rows={5} dir={k === 'body_ar' ? 'rtl' : 'ltr'} value={draft[k]} readOnly={!editable}
            onFocus={() => setLastFocus(k)} onChange={(e) => setDraft({ ...draft, [k]: e.target.value })} aria-invalid={!!err(k)} />
          {err(k) && <p className="mt-1 text-xs text-danger">{err(k)}</p>}
        </div>
      ))}

      <div>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-medium text-ink/75">{t('templates.preview')}</p>
          <Segmented name={`preview-${template.id}`} label={t('templates.preview')} value={previewLang} onChange={setPreviewLang} size="sm"
            options={[{ value: 'ar', label: t('templates.preview_ar') }, { value: 'en', label: t('templates.preview_en') }]} />
        </div>
        <div className="rounded-xl bg-page p-4">
          <p dir={previewLang === 'ar' ? 'rtl' : 'ltr'} className={`ms-auto max-w-[90%] whitespace-pre-wrap rounded-xl rounded-se-sm bg-brand-50 px-3 py-2 text-sm leading-relaxed text-ink shadow-sm ${preview.isFetching ? 'opacity-60' : ''}`}>
            {preview.data?.body ?? previewBody}
          </p>
        </div>
      </div>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {editable && (
        <div className="flex flex-wrap items-center justify-end gap-2">
          {dirty && <span className="me-auto text-xs font-medium text-gold-700">{t('templates.unsaved')}</span>}
          {dirty && <SecondaryButton onClick={() => { setDraft(initial); setFields({}); setNotice(null) }}>{t('templates.discard')}</SecondaryButton>}
          <PrimaryButton loading={save.isPending} disabled={!dirty} onClick={() => save.mutate()}>{save.isPending ? t('templates.saving') : t('templates.save')}</PrimaryButton>
        </div>
      )}
    </Card>
  )
}
