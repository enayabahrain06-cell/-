import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { certificatesApi, openObjectUrl, type CertificateTemplate, type CertificateType, type OrnamentLevel, type TemplatePayload } from '../../api/certificates'
import { parseApiError } from '../../api/client'
import Icon from '../../components/Icon'
import { StarSpinner } from '../../components/ornaments'
import { Card, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, TextArea, TextInput } from '../../components/ui'
import { formatDate } from '../../lib/format'
import { useObjectUrl } from './shared'

const PLACEHOLDERS = ['achievement', 'grade', 'lesson', 'date'] as const

/** One bilingual template per certificate type (certificates.templates). */
export default function TemplatesPanel() {
  const { t, i18n } = useTranslation('certificates')
  const q = useQuery({ queryKey: ['certificates', 'templates', i18n.language], queryFn: certificatesApi.templates })
  const [type, setType] = useState<CertificateType | null>(null)

  if (q.isLoading) return <LoadingState />
  if (q.isError || !q.data) return <ErrorState onRetry={() => void q.refetch()} />
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
  const { t, i18n } = useTranslation('certificates')
  const qc = useQueryClient()
  const initial = (tpl: CertificateTemplate): TemplatePayload => ({
    title_ar: tpl.title_ar, title_en: tpl.title_en, body_ar: tpl.body_ar, body_en: tpl.body_en,
    signature1_name: tpl.signature1_name ?? '', signature1_title: tpl.signature1_title ?? '',
    signature2_name: tpl.signature2_name ?? '', signature2_title: tpl.signature2_title ?? '',
    ornament_level: tpl.ornament_level, show_photo: !!tpl.show_photo,
  })
  const [form, setForm] = useState<TemplatePayload>(() => initial(template))
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [previewing, setPreviewing] = useState<'ar' | 'en' | null>(null)
  const bodyAr = useRef<HTMLTextAreaElement>(null)
  const bodyEn = useRef<HTMLTextAreaElement>(null)

  const dirty = JSON.stringify(form) !== JSON.stringify(initial(template))
  const update = (k: keyof TemplatePayload, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const refresh = (tpl: CertificateTemplate) => qc.setQueryData<CertificateTemplate[]>(['certificates', 'templates', i18n.language], (l) => l?.map((x) => (x.type === tpl.type ? tpl : x)))

  const save = useMutation({
    mutationFn: () => certificatesApi.saveTemplate(template.type, {
      ...form,
      signature1_name: form.signature1_name || null, signature1_title: form.signature1_title || null,
      signature2_name: form.signature2_name || null, signature2_title: form.signature2_title || null,
    }),
    onSuccess: (r) => { setErrors({}); refresh(r.data); setForm(initial(r.data)); setNotice({ tone: 'success', text: r.message || t('templates.saved') }) },
    onError: (e) => { const p = parseApiError(e); setErrors(p.fields); setNotice({ tone: 'error', text: p.message }) },
  })

  /** Insert a placeholder at the caret of the Arabic or English body. */
  const insert = (lang: 'ar' | 'en', token: string) => {
    const el = (lang === 'ar' ? bodyAr : bodyEn).current
    const key = lang === 'ar' ? 'body_ar' : 'body_en'
    const value = form[key]
    const start = el?.selectionStart ?? value.length
    const end = el?.selectionEnd ?? value.length
    const text = `{${token}}`
    update(key, value.slice(0, start) + text + value.slice(end))
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(start + text.length, start + text.length) })
  }

  const preview = async (locale: 'ar' | 'en') => {
    setPreviewing(locale)
    try {
      openObjectUrl(await certificatesApi.previewObjectUrl(template.type, locale))
    } catch {
      setNotice({ tone: 'error', text: t('templates.preview_error') })
    } finally {
      setPreviewing(null)
    }
  }
  const fe = (k: string) => errors[k]?.[0]

  return (
    <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <Card>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-display text-xl text-ink">{template.type_label}</h2>
          <div className="flex flex-wrap gap-2">
            <SecondaryButton onClick={() => void preview('ar')} disabled={previewing !== null}>
              {previewing === 'ar' ? <StarSpinner className="size-4" /> : <Icon name="eye" className="size-4" />}{t('templates.preview_ar')}
            </SecondaryButton>
            <SecondaryButton onClick={() => void preview('en')} disabled={previewing !== null}>
              {previewing === 'en' ? <StarSpinner className="size-4" /> : <Icon name="eye" className="size-4" />}{t('templates.preview_en')}
            </SecondaryButton>
          </div>
        </div>
        <p className="text-sm text-ink/60">{t('templates.intro')}</p>
        {dirty && <p className="mt-1 text-xs text-gold-700">{t('templates.unsaved')}</p>}
        {template.updated_at && <p className="mt-1 text-xs text-ink/45">{t('templates.updated', { date: formatDate(template.updated_at, i18n.language, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) })}</p>}
      </Card>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      <div className="grid gap-4 xl:grid-cols-2">
        {(['ar', 'en'] as const).map((lang) => (
          <Card key={lang} className="space-y-3">
            <div>
              <TextInput label={t(`templates.title_${lang}`)} value={form[`title_${lang}`]} onChange={(e) => update(`title_${lang}`, e.target.value)} required maxLength={150} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang} />
              {fe(`title_${lang}`) && <p className="mt-1 text-sm text-danger">{fe(`title_${lang}`)}</p>}
            </div>
            <div>
              <TextArea ref={lang === 'ar' ? bodyAr : bodyEn} label={t(`templates.body_${lang}`)} value={form[`body_${lang}`]} onChange={(e) => update(`body_${lang}`, e.target.value)} required maxLength={600} rows={4} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang} />
              {fe(`body_${lang}`) && <p className="mt-1 text-sm text-danger">{fe(`body_${lang}`)}</p>}
              <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <span className="text-xs text-ink/55">{t('templates.insert')}</span>
                {PLACEHOLDERS.map((p) => (
                  <button key={p} type="button" onClick={() => insert(lang, p)}
                    className="rounded-full border border-gold-500/40 bg-gold-500/8 px-2.5 py-0.5 text-xs font-medium text-gold-700 hover:bg-gold-500/15">
                    {t(`templates.placeholder.${p}`)} <span dir="ltr" className="font-mono text-[10px] opacity-70">{`{${p}}`}</span>
                  </button>
                ))}
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Card>
        <h3 className="mb-3 font-semibold text-ink">{t('templates.signatures')}</h3>
        <div className="grid gap-4 md:grid-cols-2">
          {([1, 2] as const).map((slot) => (
            <div key={slot} className="space-y-3 rounded-xl border border-ink/8 p-3">
              <p className="text-sm font-medium text-ink/75">{t('templates.signature_n', { n: slot })}</p>
              <div className="grid gap-3 sm:grid-cols-2">
                <TextInput label={t('templates.sig_name')} value={form[`signature${slot}_name`] ?? ''} onChange={(e) => update(`signature${slot}_name`, e.target.value)} maxLength={120} dir="auto" />
                <TextInput label={t('templates.sig_title')} value={form[`signature${slot}_title`] ?? ''} onChange={(e) => update(`signature${slot}_title`, e.target.value)} maxLength={120} dir="auto" />
              </div>
              <SignatureImage template={template} slot={slot} onChanged={(tpl, m) => { refresh(tpl); setNotice({ tone: 'success', text: m }) }} onError={(m) => setNotice({ tone: 'error', text: m })} />
            </div>
          ))}
        </div>
      </Card>

      <Card className="space-y-4">
        <h3 className="font-semibold text-ink">{t('templates.design')}</h3>
        <div>
          <p className="mb-1.5 text-sm font-medium text-ink/75">{t('templates.ornament')}</p>
          <Segmented<OrnamentLevel> name={`ornament-${template.type}`} label={t('templates.ornament')} value={form.ornament_level}
            options={(['full', 'minimal', 'off'] as const).map((v) => ({ value: v, label: t(`templates.ornament_levels.${v}`) }))}
            onChange={(v) => update('ornament_level', v)} />
        </div>
        <label className="inline-flex cursor-pointer items-center gap-3 text-sm text-ink">
          <input type="checkbox" role="switch" className="peer sr-only" checked={form.show_photo} onChange={(e) => update('show_photo', e.target.checked)} />
          <span className="relative h-6 w-11 rounded-full bg-ink/15 transition after:absolute after:start-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-brand-700 peer-checked:after:translate-x-5 peer-focus-visible:outline-2 peer-focus-visible:outline-brand-500 rtl:peer-checked:after:-translate-x-5" aria-hidden />
          {t('templates.show_photo')}
        </label>
      </Card>

      <div className="sticky bottom-3 flex justify-end">
        <PrimaryButton type="submit" loading={save.isPending} disabled={!dirty} className="shadow-lg">{t('actions.save')}</PrimaryButton>
      </div>
    </form>
  )
}

function SignatureImage({ template, slot, onChanged, onError }: {
  template: CertificateTemplate; slot: 1 | 2; onChanged: (tpl: CertificateTemplate, message: string) => void; onError: (m: string) => void
}) {
  const { t } = useTranslation('certificates')
  const input = useRef<HTMLInputElement>(null)
  const src = template.signatures[slot] ?? (template.signatures as Record<string, string | null>)[String(slot)] ?? null
  // Signature images need the bearer token, so they are fetched as blobs. A cache-buster follows each change.
  const [version, setVersion] = useState(0)
  const img = useObjectUrl(src ? () => certificatesApi.signatureObjectUrl(src) : null, [src, version])
  useEffect(() => { if (input.current) input.current.value = '' }, [version])

  const upload = useMutation({
    mutationFn: (f: File) => certificatesApi.uploadSignature(template.type, slot, f),
    onSuccess: (r) => { setVersion((v) => v + 1); onChanged(r.data, r.message) },
    onError: (e) => { const p = parseApiError(e); onError(p.fields.image?.[0] ?? p.message) },
  })
  const remove = useMutation({
    mutationFn: () => certificatesApi.removeSignature(template.type, slot),
    onSuccess: (r) => { setVersion((v) => v + 1); onChanged(r.data, r.message) },
    onError: (e) => onError(parseApiError(e).message),
  })

  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-ink/75">{t('templates.sig_image')}</p>
      <div className="flex flex-wrap items-center gap-3">
        <div className="grid h-16 w-40 place-items-center rounded-lg border border-dashed border-ink/20 bg-paper">
          {img.loading ? <StarSpinner className="size-5 text-brand-600" /> : img.url ? <img src={img.url} alt={t('templates.signature_n', { n: slot })} className="max-h-14 max-w-36 object-contain" /> : <span className="text-xs text-ink/40">{t('templates.no_image')}</span>}
        </div>
        <div className="flex flex-wrap gap-2">
          <input ref={input} type="file" accept="image/png,image/jpeg" className="sr-only" id={`sig-${template.type}-${slot}`}
            onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f) }} />
          <label htmlFor={`sig-${template.type}-${slot}`} className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-ink/12 bg-white px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5 has-[:focus-visible]:outline-2">
            {upload.isPending ? <StarSpinner className="size-4" /> : <Icon name="camera" className="size-4" />}
            {src ? t('templates.replace') : t('templates.upload')}
          </label>
          {src && (
            <SecondaryButton onClick={() => remove.mutate()} disabled={remove.isPending} className="!text-danger">
              <Icon name="trash" className="size-4" />{t('templates.remove_image')}
            </SecondaryButton>
          )}
        </div>
      </div>
      <p className="mt-1 text-xs text-ink/50">{t('templates.image_hint')}</p>
    </div>
  )
}
