import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { parseApiError, type FieldErrors } from '../../api/client'
import { settingsApi, type SettingItem, type SettingsGroup, type SettingsPayload, type SettingValue } from '../../api/settings'
import Icon from '../../components/Icon'
import SelectField from '../../components/SelectField'
import { PageBand } from '../../components/ornaments'
import { Card, ErrorState, LoadingState, Notice, PrimaryButton, SecondaryButton, Segmented, TextInput } from '../../components/ui'
import { applyOrnamentLevel, type OrnamentLevel } from '../../lib/ornament'
import MSwitch from '../../components/mobile/MSwitch'
import MobileSettings from './MobileSettings'
import { useOwnParam } from '../../app/embed'

/** Groups with a screen here, in display order. Others (e.g. messaging, managed in Messages) are not shown. */
const GROUPS = ['authority', 'locale', 'reminders', 'attendance', 'registration', 'sessions', 'progress', 'certificates', 'honor', 'ui', 'media'] as const
/** Handled outside the field list (logo card) or never shown. */
const HIDDEN_KEYS = new Set(['authority.logo_media_id'])
const LTR_KEYS = new Set(['authority.name_en', 'authority.address_en', 'authority.phone', 'locale.country_code'])
const RTL_KEYS = new Set(['authority.name_ar', 'authority.address_ar'])

export default function SettingsPage() {
  const { t } = useTranslation('settings')
  const query = useQuery({ queryKey: ['admin-settings'], queryFn: settingsApi.get })

  const groups = useMemo(() => {
    const byKey = new Map((query.data?.groups ?? []).map((g) => [g.key, g]))
    return GROUPS.map((k) => byKey.get(k)).filter((g): g is SettingsGroup => !!g)
  }, [query.data])
  const [params] = useSearchParams()
  const askedGroup = useOwnParam(params, 'group')
  const openGroup = groups.find((g) => g.key === askedGroup)

  return (
    <>
    <MobileSettings groups={groups} loading={query.isLoading} error={query.isError} onRetry={() => void query.refetch()} open={openGroup}
      ornament={groups.find((g) => g.key === 'ui')?.settings.find((x) => x.key === 'ui.ornament_level')?.value as string | undefined}
      renderGroup={(g) => <GroupCard group={g} logoUrl={query.data?.logo_url ?? null} anchor={false} mobile />} />
    <div className="hidden space-y-5 lg:block">
      <PageBand title={t('title')} subtitle={t('subtitle')} />

      {query.isLoading ? (
        <LoadingState />
      ) : query.isError || !query.data ? (
        <ErrorState onRetry={() => void query.refetch()} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
          <SectionIndex groups={groups} />
          <div className="min-w-0 space-y-6">
            {groups.map((g) => (
              <GroupCard key={g.key} group={g} logoUrl={query.data.logo_url} />
            ))}
          </div>
        </div>
      )}
    </div>
    </>
  )
}

/** Sticky side index on desktop; a horizontally scrolling chip row on small screens. */
function SectionIndex({ groups }: { groups: SettingsGroup[] }) {
  const { t } = useTranslation('settings')
  const [active, setActive] = useState<string | null>(groups[0]?.key ?? null)

  useEffect(() => {
    const els = groups.map((g) => document.getElementById(`settings-${g.key}`)).filter((e): e is HTMLElement => !!e)
    if (!('IntersectionObserver' in window) || els.length === 0) return
    const io = new IntersectionObserver(
      (entries) => {
        const top = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0]
        if (top) setActive(top.target.id.replace('settings-', ''))
      },
      { rootMargin: '0px 0px -60% 0px' },
    )
    els.forEach((e) => io.observe(e))
    return () => io.disconnect()
  }, [groups])

  return (
    <nav aria-label={t('index')} className="min-w-0 lg:sticky lg:top-4 lg:self-start">
      <ul className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:px-0 lg:pb-0">
        {groups.map((g) => (
          <li key={g.key} className="shrink-0">
            <a
              href={`#settings-${g.key}`}
              aria-current={active === g.key ? 'true' : undefined}
              className={`block whitespace-nowrap rounded-full px-3 py-1.5 text-sm transition lg:rounded-lg ${
                active === g.key ? 'bg-brand-700 text-white lg:bg-brand-50 lg:font-semibold lg:text-brand-800' : 'bg-white text-ink/70 ring-1 ring-ink/10 hover:text-ink lg:bg-transparent lg:ring-0 lg:hover:bg-ink/5'
              }`}
            >
              {t(`groups.${g.key}.title`)}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}

/** `anchor` false: no section id (the mobile copy of a group, so ids stay unique); `mobile`: 44px switches. */
function GroupCard({ group, logoUrl, anchor = true, mobile = false }: { group: SettingsGroup; logoUrl: string | null; anchor?: boolean; mobile?: boolean }) {
  const { t } = useTranslation('settings')
  const qc = useQueryClient()
  const items = useMemo(() => group.settings.filter((s) => !HIDDEN_KEYS.has(s.key)), [group.settings])
  const initial = useMemo(() => Object.fromEntries(items.map((s) => [s.key, s.value])) as Record<string, SettingValue>, [items])
  // Only edited keys live in state; everything else reads the stored value, so a fresh payload needs no reset effect.
  const [edits, setEdits] = useState<Record<string, SettingValue>>({})
  const draft = { ...initial, ...edits }
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const dirty = items.filter((s) => s.editable && draft[s.key] !== initial[s.key]).map((s) => s.key)

  const save = useMutation({
    mutationFn: () => settingsApi.update(Object.fromEntries(dirty.map((k) => [k, draft[k]]))),
    onSuccess: (res) => {
      setErrors({})
      setEdits({})
      setNotice({ tone: 'success', text: res.message })
      qc.setQueryData<SettingsPayload>(['admin-settings'], res.data)
      void qc.invalidateQueries({ queryKey: ['public-settings'] })
      if (res.changed.includes('ui.ornament_level')) applyOrnamentLevel(draft['ui.ornament_level'] as OrnamentLevel)
    },
    onError: (e) => {
      const { message, fields } = parseApiError(e)
      setErrors(fields)
      // Field errors show under each field; the notice only summarises.
      setNotice({ tone: 'error', text: Object.keys(fields).length ? t('check_fields') : message })
    },
  })

  const set = (key: string, value: SettingValue) => {
    setEdits((d) => ({ ...d, [key]: value }))
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([k]) => k !== key)))
    setNotice(null)
  }

  return (
    <Card as="section" id={anchor ? `settings-${group.key}` : undefined} aria-labelledby={`${anchor ? '' : 'm-'}settings-${group.key}-title`} className="scroll-mt-4">
      <header className="mb-4">
        <h2 id={`${anchor ? '' : 'm-'}settings-${group.key}-title`} className="text-base font-semibold text-ink">{t(`groups.${group.key}.title`)}</h2>
        <p className="mt-0.5 text-sm text-ink/55">{t(`groups.${group.key}.desc`)}</p>
      </header>

      {group.key === 'authority' ? (
        <AuthorityLayout items={items} draft={draft} errors={errors} onChange={set} logoUrl={logoUrl} />
      ) : (
        <div className="divide-y divide-ink/6">
          {items.map((s) => (
            <Field key={s.key} item={s} value={draft[s.key]} error={errors[s.key]?.[0]} onChange={(v) => set(s.key, v)} mobile={mobile} />
          ))}
        </div>
      )}

      {group.key === 'registration' && (
        <p className="mt-3 text-sm text-ink/60">
          {t('age_groups_note')}{' '}
          <Link to="/lessons?tab=age-groups" className="font-medium text-brand-700 hover:underline">{t('age_groups_link')}</Link>
        </p>
      )}

      <footer className="mt-4 flex flex-wrap items-center gap-3 border-t border-ink/6 pt-4">
        <PrimaryButton onClick={() => save.mutate()} disabled={dirty.length === 0} loading={save.isPending}>
          {save.isPending ? t('saving') : t('save')}
        </PrimaryButton>
        {dirty.length > 0 && (
          <>
            <SecondaryButton onClick={() => { setEdits({}); setErrors({}); setNotice(null) }}>{t('discard')}</SecondaryButton>
            <span className="text-sm text-gold-700">{t('unsaved', { count: dirty.length })}</span>
          </>
        )}
        {notice && <div className="w-full"><Notice tone={notice.tone}>{notice.text}</Notice></div>}
      </footer>
    </Card>
  )
}

const AUTHORITY_ROWS = [
  { label: 'name', keys: ['authority.name_ar', 'authority.name_en'] },
  { label: 'address', keys: ['authority.address_ar', 'authority.address_en'] },
  { label: 'phone', keys: ['authority.phone'] },
] as const

/**
 * Authority identity: a bilingual grid (row label | Arabic | English) beside a live preview card that holds the logo.
 * Keys the registry adds later and the grid does not know still render, as a plain row under it.
 */
function AuthorityLayout({ items, draft, errors, onChange, logoUrl }: {
  items: SettingItem[]; draft: Record<string, SettingValue>; errors: FieldErrors; onChange: (key: string, v: SettingValue) => void; logoUrl: string | null
}) {
  const { t, i18n } = useTranslation('settings')
  const localAddress = i18n.language === 'en' ? 'authority.address_en' : 'authority.address_ar'
  const byKey = new Map(items.map((s) => [s.key, s]))
  const known = new Set<string>(AUTHORITY_ROWS.flatMap((r) => r.keys))
  const rest = items.filter((s) => !known.has(s.key))
  const field = (key: string) => {
    const s = byKey.get(key)
    return s ? <Field key={key} item={s} value={draft[key]} error={errors[key]?.[0]} onChange={(v) => onChange(key, v)} stacked /> : null
  }
  const text = (key: string) => (typeof draft[key] === 'string' ? (draft[key] as string).trim() : '')

  return (
    <div className="space-y-5">
      <aside aria-label={t('authority.preview')} className="rounded-2xl border border-ink/8 bg-page/60 p-4">
        <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
          <LogoTile url={logoUrl} />
          <div className="min-w-0 flex-1 basis-40">
            <p lang="ar" dir="rtl" className="truncate text-start font-display text-xl leading-snug text-ink">{text('authority.name_ar') || t('authority.empty')}</p>
            <p lang="en" dir="ltr" className="truncate text-start text-sm text-ink/60">{text('authority.name_en') || t('authority.empty')}</p>
          </div>
          <dl className="min-w-0 flex-1 basis-52 space-y-1 text-sm text-ink/70 sm:border-s sm:border-ink/8 sm:ps-5">
            <div className="flex items-start gap-2">
              <dt className="sr-only">{t('authority.address')}</dt>
              <Icon name="pin" className="mt-0.5 size-4 shrink-0 text-ink/40" />
              <dd className="min-w-0 truncate" dir="auto">{text(localAddress) || text('authority.address_ar') || text('authority.address_en') || '—'}</dd>
            </div>
            <div className="flex items-center gap-2">
              <dt className="sr-only">{t('authority.phone')}</dt>
              <Icon name="phone" className="size-4 shrink-0 text-ink/40" />
              <dd dir="ltr" className="tabular-nums">{text('authority.phone') || '—'}</dd>
            </div>
          </dl>
          <LogoField url={logoUrl} />
        </div>
        <p className="mt-3 text-xs leading-relaxed text-ink/50">{t('authority.preview_note')} {t('authority.logo_help')}</p>
      </aside>

      {/* Bilingual columns only when the card itself is wide enough (container query), not by viewport. */}
      <div className="@container min-w-0">
        <div className="grid gap-x-4 gap-y-4 @xl:grid-cols-[6rem_minmax(0,1fr)_minmax(0,1fr)] @xl:gap-y-3">
          <div aria-hidden className="hidden @xl:contents">
            <span />
            <span lang="ar" dir="rtl" className="text-start text-xs font-semibold text-ink/50">{t('authority.arabic')}</span>
            <span lang="en" dir="ltr" className="text-start text-xs font-semibold text-ink/50">{t('authority.english')}</span>
          </div>
          {AUTHORITY_ROWS.map((r) => (
            <div key={r.label} className="contents">
              <span aria-hidden className="hidden pt-2.5 text-sm font-medium text-ink @xl:block">{t(`authority.${r.label}`)}</span>
              {field(r.keys[0])}
              {r.keys[1] ? field(r.keys[1]) : <span className="hidden @xl:block" />}
            </div>
          ))}
        </div>
        {rest.length > 0 && (
          <div className="mt-2 divide-y divide-ink/6">
            {rest.map((s) => <Field key={s.key} item={s} value={draft[s.key]} error={errors[s.key]?.[0]} onChange={(v) => onChange(s.key, v)} />)}
          </div>
        )}
      </div>

    </div>
  )
}

function LogoTile({ url }: { url: string | null }) {
  const { t } = useTranslation('settings')
  return (
    <div className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl border border-dashed border-ink/20 bg-white">
      {url ? <img src={url} alt={t('logo.alt')} className="size-full object-contain p-1" /> : <Icon name="camera" className="size-6 text-ink/30" />}
    </div>
  )
}

/** `stacked`: a full-width control for the authority grid (label shown above it only on phones) instead of the label | control row. */
function Field({ item: s, value, error, onChange, mobile = false, stacked = false }: { item: SettingItem; value: SettingValue; error?: string; onChange: (v: SettingValue) => void; mobile?: boolean; stacked?: boolean }) {
  const { t, i18n } = useTranslation('settings')
  const id = useId()
  const label = t(`fields.${s.key}.label`, { defaultValue: s.key })
  const help = t(`fields.${s.key}.help`, { defaultValue: '' })
  const helpId = `${id}-help`
  const errId = `${id}-err`
  const describedBy = [help && helpId, error && errId].filter(Boolean).join(' ') || undefined
  const optLabel = (o: string) => t(`options.${s.key}.${o}`, { defaultValue: o })

  let control: React.ReactNode
  if (!s.editable) {
    control = (
      <p className="inline-flex items-center gap-1.5 rounded-lg bg-ink/5 px-3 py-2 text-sm text-ink/70" dir="auto">
        <span className="font-medium text-ink">{value === null || value === '' ? '—' : String(value)}</span>
        <span className="text-xs text-ink/50">· {t('read_only')}</span>
      </p>
    )
  } else if (s.type === 'bool' && mobile) {
    control = <MSwitch checked={value === true} onChange={onChange} labelledBy={`${id}-label`} />
  } else if (s.type === 'bool') {
    control = <Toggle id={id} checked={value === true} onChange={onChange} describedBy={describedBy} />
  } else if (s.options && s.options.length <= 4) {
    control = <Segmented name={id} label={label} value={String(value ?? '')} options={s.options.map((o) => ({ value: o, label: optLabel(o) }))} onChange={onChange} />
  } else if (s.options) {
    const opts = s.key === 'locale.timezone' ? s.options.map((o) => ({ value: o, label: o.replaceAll('_', ' ') })) : s.options.map((o) => ({ value: o, label: optLabel(o) }))
    control = (
      <SelectField id={id} label={label} hideLabel value={String(value ?? '')} options={opts} onChange={(e) => onChange(e.target.value)}
        aria-invalid={!!error} aria-describedby={describedBy} className="w-full max-w-72" dir={s.key === 'locale.timezone' ? 'ltr' : undefined} />
    )
  } else if (s.type === 'int') {
    const unit = t(`units.${s.key}`, { defaultValue: '' })
    control = (
      // Wraps instead of overflowing; the allowed range sits beside the unit only when the control column has room (container query).
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <input
          id={id}
          type="number"
          inputMode="numeric"
          min={s.min}
          max={s.max}
          value={value === null ? '' : String(value)}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
          aria-invalid={!!error}
          aria-describedby={describedBy}
          className={`block w-28 rounded-xl border bg-white px-3 py-2 text-sm tabular-nums shadow-sm focus:outline-none focus:ring-4 ${error ? 'border-danger/60 focus:ring-danger/15' : 'border-ink/15 focus:border-brand-500 focus:ring-brand-100'}`}
        />
        {unit && <span className="whitespace-nowrap text-sm text-ink/60">{unit}</span>}
        {s.min !== undefined && s.max !== undefined && (
          <span className="basis-full text-xs text-ink/45 @2xs:basis-auto">{t('range', { min: s.min.toLocaleString(i18n.language === 'ar' ? 'ar-BH' : 'en-BH'), max: s.max.toLocaleString(i18n.language === 'ar' ? 'ar-BH' : 'en-BH') })}</span>
        )}
      </div>
    )
  } else {
    control = (
      <TextInput id={id} label={label} hideLabel value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}
        dir={LTR_KEYS.has(s.key) ? 'ltr' : RTL_KEYS.has(s.key) ? 'rtl' : 'auto'} aria-invalid={!!error} aria-describedby={describedBy}
        className={stacked ? 'w-full' : 'w-full sm:max-w-md'} type={s.key === 'authority.phone' ? 'tel' : 'text'} />
    )
  }

  const labelEl = s.type === 'bool' || (s.options && s.options.length <= 4) || !s.editable
    ? <span id={`${id}-label`} className="text-sm font-medium text-ink">{label}</span>
    : <label htmlFor={id} className="text-sm font-medium text-ink">{label}</label>

  if (stacked) {
    // The authority grid names rows and columns visually once its container is @xl; the control keeps its own (sr-only) full label.
    return (
      <div className="min-w-0">
        <span aria-hidden className="mb-1.5 block text-sm font-medium text-ink @xl:hidden">{label}</span>
        {control}
        {help && <p id={helpId} className="mt-1 text-xs leading-relaxed text-ink/55">{help}</p>}
        {error && <p id={errId} dir="auto" className="mt-1.5 text-start text-sm text-danger">{error}</p>}
      </div>
    )
  }

  return (
    <div className="grid gap-2 py-3.5 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] sm:gap-6">
      <div>
        {labelEl}
        {help && <p id={helpId} className="mt-0.5 text-xs leading-relaxed text-ink/55">{help}</p>}
      </div>
      <div className="@container min-w-0">
        {control}
        {error && <p id={errId} dir="auto" className="mt-1.5 text-start text-sm text-danger">{error}</p>}
      </div>
    </div>
  )
}

function Toggle({ id, checked, onChange, describedBy }: { id: string; checked: boolean; onChange: (v: boolean) => void; describedBy?: string }) {
  const { t } = useTranslation('settings')
  return (
    <button
      id={id}
      type="button"
      role="switch"
      aria-checked={checked}
      aria-labelledby={`${id}-label`}
      aria-describedby={describedBy}
      onClick={() => onChange(!checked)}
      className="group inline-flex items-center gap-2.5 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
    >
      <span className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${checked ? 'bg-brand-600' : 'bg-ink/20'}`}>
        <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${checked ? 'start-[1.375rem]' : 'start-0.5'}`} />
      </span>
      <span className={`text-sm ${checked ? 'text-brand-700' : 'text-ink/55'}`}>{checked ? t('on') : t('off')}</span>
    </button>
  )
}

function LogoField({ url }: { url: string | null }) {
  const { t } = useTranslation('settings')
  const qc = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const done = (res: { message: string; data: SettingsPayload }) => {
    setNotice({ tone: 'success', text: res.message })
    qc.setQueryData<SettingsPayload>(['admin-settings'], res.data)
    void qc.invalidateQueries({ queryKey: ['public-settings'] })
  }
  const fail = (e: unknown) => {
    const { message, fields } = parseApiError(e)
    setNotice({ tone: 'error', text: fields.logo?.[0] ?? message })
  }
  const upload = useMutation({ mutationFn: settingsApi.uploadLogo, onSuccess: done, onError: fail })
  const remove = useMutation({ mutationFn: settingsApi.deleteLogo, onSuccess: done, onError: fail })

  return (
    // Logo actions at the end of the authority preview banner (the tile itself is LogoTile).
    <div className="space-y-2">
      <div className="flex flex-wrap gap-2">
          <input
            ref={input}
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) upload.mutate(f)
              e.target.value = ''
            }}
          />
          <SecondaryButton onClick={() => input.current?.click()} disabled={upload.isPending}>
            <Icon name="camera" className="size-4" />
            {upload.isPending ? t('logo.uploading') : url ? t('logo.replace') : t('logo.upload')}
          </SecondaryButton>
          {url && (
            <SecondaryButton onClick={() => remove.mutate()} disabled={remove.isPending} className="text-danger">
              {t('logo.remove')}
            </SecondaryButton>
          )}
      </div>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </div>
  )
}
