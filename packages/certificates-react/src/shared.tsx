import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCertificates, useCertT } from './context'
import type { Certificate, CertificateStatus } from './types'
import type { Tone } from './ui'

export const STATUS_TONE: Record<CertificateStatus, Tone> = { draft: 'gold', approved: 'brand', revoked: 'danger' }

export function StatusBadge({ c }: { c: Pick<Certificate, 'status' | 'status_label'> }) {
  const { ui } = useCertificates()
  return (
    <ui.Badge tone={STATUS_TONE[c.status]} className={c.status === 'revoked' ? 'line-through decoration-danger/60' : ''}>
      {c.status_label}
    </ui.Badge>
  )
}

/** Gregorian date with the host's second calendar (e.g. Hijri) underneath. */
export function IssuedDate({ c, short = false }: { c: Pick<Certificate, 'issued_on' | 'issued_on_secondary'>; short?: boolean }) {
  const { locale } = useCertT()
  const { formatDate } = useCertificates()
  if (!c.issued_on) return <span>—</span>
  return (
    <span>
      <span className="block">{formatDate(c.issued_on, locale, short ? { day: 'numeric', month: 'short', year: 'numeric' } : undefined)}</span>
      {c.issued_on_secondary && <span className="block text-xs text-ink/50">{c.issued_on_secondary}</span>}
    </span>
  )
}

/** Display title: "Certificate of Memorization — Juz Amma". */
export const displayTitle = (c: Pick<Certificate, 'title' | 'achievement'>) => (c.achievement && !c.title.includes(c.achievement) ? `${c.title} — ${c.achievement}` : c.title)

/** Object URL of an authenticated PDF (or image), revoked on unmount. */
export function useObjectUrl(load: (() => Promise<string>) | null, deps: unknown[]) {
  const [state, setState] = useState<{ url: string | null; error: boolean; loading: boolean }>({ url: null, error: false, loading: !!load })
  useEffect(() => {
    if (!load) {
      setState({ url: null, error: false, loading: false })
      return
    }
    let alive = true
    let made: string | null = null
    setState({ url: null, error: false, loading: true })
    load()
      .then((u) => {
        made = u
        if (alive) setState({ url: u, error: false, loading: false })
        else URL.revokeObjectURL(u)
      })
      .catch(() => alive && setState({ url: null, error: true, loading: false }))
    return () => {
      alive = false
      if (made) URL.revokeObjectURL(made)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
  return state
}

/** Refresh every certificate view after a change (list, recipient tab, and the host's own screens). */
export function useInvalidateCertificates() {
  const qc = useQueryClient()
  const { onChanged } = useCertificates()
  return () => {
    void qc.invalidateQueries({ queryKey: ['certificates'] })
    onChanged()
  }
}

/** Type, status, grade and source labels (staff endpoint). Pass enabled=false where none is needed, e.g. read-only family pages. */
export function useCertificateOptions(enabled = true) {
  const { api } = useCertificates()
  const { locale } = useCertT()
  return useQuery({ queryKey: ['certificates', 'options', locale], queryFn: api.options, staleTime: 5 * 60_000, enabled })
}

/**
 * Small CSS mock of the printed certificate (gold double frame, title, recipient, grade).
 * The server renders only PDFs, so the card thumbnail is drawn here.
 */
export function CertificateThumb({ c, className = '' }: { c: Certificate; className?: string }) {
  const { t } = useCertT()
  const revoked = c.status === 'revoked'
  const draft = c.status === 'draft'
  return (
    <div aria-hidden className={`relative aspect-[1.414/1] w-full overflow-hidden rounded-lg bg-paper p-1.5 shadow-inner ${className}`}>
      <div className="h-full rounded-[5px] border-2 border-gold-500 p-[3px]">
        <div className="relative flex h-full flex-col items-center justify-center gap-0.5 rounded-[3px] border border-gold-400/80 px-2 text-center">
          <span className="absolute start-1 top-1 size-1.5 rotate-45 bg-gold-400" />
          <span className="absolute end-1 top-1 size-1.5 rotate-45 bg-gold-400" />
          <span className="absolute bottom-1 start-1 size-1.5 rotate-45 bg-gold-400" />
          <span className="absolute bottom-1 end-1 size-1.5 rotate-45 bg-gold-400" />
          <span className="font-display text-[11px] leading-tight text-gold-700">{t('thumb.heading')}</span>
          <span dir="auto" className="line-clamp-1 font-display text-sm leading-tight text-brand-900">{c.title}</span>
          <span className="h-px w-10 bg-gold-400/70" />
          <span className="text-[8px] text-ink/45">{t('thumb.awarded_to')}</span>
          <span dir="auto" className="line-clamp-1 text-[11px] font-semibold leading-tight text-ink">{c.recipient?.name ?? ''}</span>
          <span dir="auto" className="line-clamp-1 text-[9px] text-ink/60">{c.achievement}</span>
          {c.grade_label && <span className="mt-0.5 rounded-full bg-gold-500/15 px-1.5 text-[8px] font-medium text-gold-700">{c.grade_label}</span>}
        </div>
      </div>
      {(revoked || draft) && (
        <span className={`absolute inset-0 grid place-items-center ${revoked ? 'bg-white/40' : ''}`}>
          <span className={`-rotate-12 rounded border-2 px-2 py-0.5 text-xs font-bold uppercase tracking-wider ${revoked ? 'border-danger/70 text-danger/80' : 'border-gold-500/60 text-gold-700/70'}`}>
            {c.status_label}
          </span>
        </span>
      )}
    </div>
  )
}
