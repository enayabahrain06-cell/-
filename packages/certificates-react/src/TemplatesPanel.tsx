import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { openObjectUrl } from './api'
import { useCertificates, useCertT } from './context'
import { useCertificateOptions, useObjectUrl } from './shared'
import type { CertificateTemplate, OrnamentLevel, TemplatePayload } from './types'

const RTL = new Set(['ar', 'fa', 'he', 'ur'])
const SLOTS = [1, 2] as const

/** One template per certificate type, with a title and body in every configured language (certificates.templates). */
export default function TemplatesPanel() {
  const { t, locale } = useCertT()
  const { api, ui } = useCertificates()
  const q = useQuery({ queryKey: ['certificates', 'templates', locale], queryFn: api.templates })
  const [type, setType] = useState<string | null>(null)

  if (q.isLoading) return <ui.LoadingState />
  if (q.isError || !q.data) return <ui.ErrorState onRetry={() => void q.refetch()} />
  const current = q.data.find((x) => x.type === type) ?? q.data[0]
  if (!current) return null

  return (
    <div className="grid gap-4 lg:grid-cols-[14rem_1fr]">
      <nav aria-label={t('templates.type')} className="flex gap-1 overflow-x-auto lg:flex-col">
        {q.data.map((tpl) => (
          <button key={tpl.type} type="button" onClick={() => setType(tpl.type)} aria-current={tpl.type === current.type ? 'true' : undefined}
            className={`shrink-0 rounded-xl px-4 py-2.5 text-start text-sm font-medium transition ${tpl.type === current.type ? 'bg-brand-700 text-white shadow-sm' : 'bg-white text-ink/70 shadow-sm ring-1 ring-ink/8 hover:text-ink'}`}>
            {tpl.type_label}
          </button>
        ))}
      </nav>
      {/* key: reset the form when switching templates */}
      <TemplateEditor key={current.type} template={current} />
    </div>
  )
}

function TemplateEditor({ template }: { template: CertificateTemplate }) {
  const { t, locale } = useCertT()
  const { api, ui, parseError, formatDate } = useCertificates()
  const qc = useQueryClient()
  const options = useCertificateOptions()
  const locales = options.data?.locales ?? Object.keys(template.title)
  const placeholders = options.data?.placeholders ?? ['name', 'achievement', 'grade', 'context', 'date']

  const initial = (tpl: CertificateTemplate): TemplatePayload => ({
    title: { ...tpl.title }, body: { ...tpl.body },
    signature1_name: tpl.signature1_name ?? '', signature1_title: tpl.signature1_title ?? '',
    signature2_name: tpl.signature2_name ?? '', signature2_title: tpl.signature2_title ?? '',
    ornament_level: tpl.ornament_level, show_photo: !!tpl.show_photo,
  })
  const [form, setForm] = useState<TemplatePayload>(() => initial(template))
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [previewing, setPreviewing] = useState<string | null>(null)
  const bodies = useRef<Record<string, HTMLTextAreaElement | null>>({})

  const dirty = JSON.stringify(form) !== JSON.stringify(initial(template))
  const update = <K extends keyof TemplatePayload>(k: K, v: TemplatePayload[K]) => setForm((f) => ({ ...f, [k]: v }))
  const setText = (field: 'title' | 'body', lang: string, v: string) => setForm((f) => ({ ...f, [field]: { ...f[field], [lang]: v } }))
  const refresh = (tpl: CertificateTemplate) => qc.setQueryData<CertificateTemplate[]>(['certificates', 'templates', locale], (l) => l?.map((x) => (x.type === tpl.type ? tpl : x)))

  const save = useMutation({
    mutationFn: () => api.saveTemplate(template.type, {
      ...form,
      signature1_name: form.signature1_name || null, signature1_title: form.signature1_title || null,
      signature2_name: form.signature2_name || null, signature2_title: form.signature2_title || null,
    }),
    onSuccess: (r) => { setErrors({}); refresh(r.data); setForm(initial(r.data)); setNotice({ tone: 'success', text: r.message || t('templates.saved') }) },
    onError: (e) => { const p = parseError(e); setErrors(p.fields); setNotice({ tone: 'error', text: p.message }) },
  })

  /** Insert a placeholder at the caret of one language's body. */
  const insert = (lang: string, token: string) => {
    const el = bodies.current[lang]
    const value = form.body[lang] ?? ''
    const start = el?.selectionStart ?? value.length
    const end = el?.selectionEnd ?? value.length
    const text = `{${token}}`
    setText('body', lang, value.slice(0, start) + text + value.slice(end))
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + text.length, start + text.length) })
  }

  const preview = async (lang: string) => {
    setPreviewing(lang)
    try {
      openObjectUrl(await api.previewObjectUrl(template.type, lang))
    } catch {
      setNotice({ tone: 'error', text: t('templates.preview_error') })
    } finally {
      setPreviewing(null)
    }
  }
  const fe = (k: string) => errors[k]?.[0]
  const langName = (lang: string) => t(`languages.${lang}`, { defaultValue: lang.toUpperCase() })

  return (
    <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <ui.Card>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold text-ink">{template.type_label}</h2>
          <div className="flex flex-wrap gap-2">
            {locales.map((lang) => (
              <ui.SecondaryButton key={lang} onClick={() => void preview(lang)} disabled={previewing !== null}>
                {previewing === lang ? <ui.Spinner className="size-4" /> : <ui.Icon name="eye" className="size-4" />}{t('templates.preview_in', { language: langName(lang) })}
              </ui.SecondaryButton>
            ))}
          </div>
        </div>
        <p className="text-sm text-ink/60">{t('templates.intro')}</p>
        {dirty && <p className="mt-1 text-xs text-gold-700">{t('templates.unsaved')}</p>}
        {template.updated_at && <p className="mt-1 text-xs text-ink/45">{t('templates.updated', { date: formatDate(template.updated_at, locale, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) })}</p>}
      </ui.Card>

      {notice && <ui.Notice tone={notice.tone}>{notice.text}</ui.Notice>}

      <div className={`grid gap-4 ${locales.length > 1 ? 'xl:grid-cols-2' : ''}`}>
        {locales.map((lang) => {
          const dir = RTL.has(lang) ? 'rtl' : 'ltr'
          return (
            <ui.Card key={lang} className="space-y-3">
              <div>
                <ui.TextInput label={t('templates.title_in', { language: langName(lang) })} value={form.title[lang] ?? ''} onChange={(e) => setText('title', lang, e.target.value)} required maxLength={150} dir={dir} lang={lang} />
                {fe(`title.${lang}`) && <p className="mt-1 text-sm text-danger">{fe(`title.${lang}`)}</p>}
              </div>
              <div>
                <ui.TextArea ref={(el: HTMLTextAreaElement | null) => { bodies.current[lang] = el }} label={t('templates.body_in', { language: langName(lang) })} value={form.body[lang] ?? ''} onChange={(e) => setText('body', lang, e.target.value)} required maxLength={600} rows={4} dir={dir} lang={lang} />
                {fe(`body.${lang}`) && <p className="mt-1 text-sm text-danger">{fe(`body.${lang}`)}</p>}
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                  <span className="text-xs text-ink/55">{t('templates.insert')}</span>
                  {placeholders.map((p) => (
                    <button key={p} type="button" onClick={() => insert(lang, p)}
                      className="rounded-full border border-gold-500/40 bg-gold-500/8 px-2.5 py-0.5 text-xs font-medium text-gold-700 hover:bg-gold-500/15">
                      {t(`templates.placeholder.${p}`, { defaultValue: p })} <span dir="ltr" className="font-mono text-xs opacity-80">{`{${p}}`}</span>
                    </button>
                  ))}
                </div>
              </div>
            </ui.Card>
          )
        })}
      </div>

      <ui.Card>
        <h3 className="mb-3 font-semibold text-ink">{t('templates.signatures')}</h3>
        <div className="grid gap-4 md:grid-cols-2">
          {SLOTS.map((slot) => (
            <div key={slot} className="space-y-3 rounded-xl border border-ink/8 p-3">
              <p className="text-sm font-medium text-ink/75">{t('templates.signature_n', { n: slot })}</p>
              <div className="grid gap-3 sm:grid-cols-2">
                <ui.TextInput label={t('templates.sig_name')} value={form[`signature${slot}_name`] ?? ''} onChange={(e) => update(`signature${slot}_name`, e.target.value)} maxLength={120} dir="auto" />
                <ui.TextInput label={t('templates.sig_title')} value={form[`signature${slot}_title`] ?? ''} onChange={(e) => update(`signature${slot}_title`, e.target.value)} maxLength={120} dir="auto" />
              </div>
              <SignatureImage template={template} slot={slot} onChanged={(tpl, m) => { refresh(tpl); setNotice({ tone: 'success', text: m }) }} onError={(m) => setNotice({ tone: 'error', text: m })} />
            </div>
          ))}
        </div>
      </ui.Card>

      <ui.Card className="space-y-4">
        <h3 className="font-semibold text-ink">{t('templates.design')}</h3>
        <div>
          <p className="mb-1.5 text-sm font-medium text-ink/75">{t('templates.ornament')}</p>
          <ui.Segmented<OrnamentLevel> name={`ornament-${template.type}`} label={t('templates.ornament')} value={form.ornament_level}
            options={(['full', 'minimal', 'off'] as const).map((v) => ({ value: v, label: t(`templates.ornament_levels.${v}`) }))}
            onChange={(v) => update('ornament_level', v)} />
        </div>
        <label className="inline-flex cursor-pointer items-center gap-3 text-sm text-ink">
          <input type="checkbox" role="switch" className="peer sr-only" checked={form.show_photo} onChange={(e) => update('show_photo', e.target.checked)} />
          <span className="relative h-6 w-11 rounded-full bg-ink/15 transition after:absolute after:start-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-brand-700 peer-checked:after:translate-x-5 peer-focus-visible:outline-2 peer-focus-visible:outline-brand-500 rtl:peer-checked:after:-translate-x-5" aria-hidden />
          {t('templates.show_photo')}
        </label>
      </ui.Card>

      <div className="sticky bottom-3 flex justify-end">
        <ui.PrimaryButton type="submit" loading={save.isPending} disabled={!dirty} className="shadow-lg">{t('actions.save')}</ui.PrimaryButton>
      </div>
    </form>
  )
}

function SignatureImage({ template, slot, onChanged, onError }: {
  template: CertificateTemplate; slot: number; onChanged: (tpl: CertificateTemplate, message: string) => void; onError: (m: string) => void
}) {
  const { t } = useCertT()
  const { api, ui, parseError } = useCertificates()
  const input = useRef<HTMLInputElement>(null)
  const src = template.signatures[String(slot)] ?? null
  // Signature images need the session, so they are fetched as blobs. A cache-buster follows each change.
  const [version, setVersion] = useState(0)
  const img = useObjectUrl(src ? () => api.signatureObjectUrl(src) : null, [src, version])
  useEffect(() => { if (input.current) input.current.value = '' }, [version])

  const upload = useMutation({
    mutationFn: (f: File) => api.uploadSignature(template.type, slot, f),
    onSuccess: (r) => { setVersion((v) => v + 1); onChanged(r.data, r.message) },
    onError: (e) => { const p = parseError(e); onError(p.fields.image?.[0] ?? p.message) },
  })
  const remove = useMutation({
    mutationFn: () => api.removeSignature(template.type, slot),
    onSuccess: (r) => { setVersion((v) => v + 1); onChanged(r.data, r.message) },
    onError: (e) => onError(parseError(e).message),
  })

  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-ink/75">{t('templates.sig_image')}</p>
      <div className="flex flex-wrap items-center gap-3">
        <div className="grid h-16 w-40 place-items-center rounded-lg border border-dashed border-ink/20 bg-paper">
          {img.loading ? <ui.Spinner className="size-5 text-brand-600" /> : img.url ? <img src={img.url} alt={t('templates.signature_n', { n: slot })} className="max-h-14 max-w-36 object-contain" /> : <span className="text-xs text-ink/40">{t('templates.no_image')}</span>}
        </div>
        <div className="flex flex-wrap gap-2">
          <input ref={input} type="file" accept="image/png,image/jpeg" className="sr-only" id={`sig-${template.type}-${slot}`}
            onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f) }} />
          <label htmlFor={`sig-${template.type}-${slot}`} className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-ink/12 bg-white px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5 has-[:focus-visible]:outline-2">
            {upload.isPending ? <ui.Spinner className="size-4" /> : <ui.Icon name="camera" className="size-4" />}
            {src ? t('templates.replace') : t('templates.upload')}
          </label>
          {src && (
            <ui.SecondaryButton onClick={() => remove.mutate()} disabled={remove.isPending} className="!text-danger">
              <ui.Icon name="trash" className="size-4" />{t('templates.remove_image')}
            </ui.SecondaryButton>
          )}
        </div>
      </div>
      <p className="mt-1 text-xs text-ink/50">{t('templates.image_hint')}</p>
    </div>
  )
}
