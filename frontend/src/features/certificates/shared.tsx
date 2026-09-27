import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import type { Certificate, CertificateStatus } from '../../api/certificates'
import { Badge, type Tone } from '../../components/ui'
import { formatDate } from '../../lib/format'

export const STATUS_TONE: Record<CertificateStatus, Tone> = { draft: 'gold', approved: 'brand', revoked: 'danger' }

export function StatusBadge({ c }: { c: Pick<Certificate, 'status' | 'status_label'> }) {
  return (
    <Badge tone={STATUS_TONE[c.status]} className={c.status === 'revoked' ? 'line-through decoration-danger/60' : ''}>
      {c.status_label}
    </Badge>
  )
}

/** "16 ربيع الآخر 1448 هـ · 27 سبتمبر 2026" — the API's Hijri string next to the Gregorian date. */
export function IssuedDate({ c, short = false }: { c: Pick<Certificate, 'issued_on' | 'issued_on_hijri'>; short?: boolean }) {
  const { i18n } = useTranslation()
  if (!c.issued_on) return <span>—</span>
  const greg = formatDate(c.issued_on, i18n.language, short ? { day: 'numeric', month: 'short', year: 'numeric' } : undefined)
  return (
    <span>
      <span className="block">{greg}</span>
      {c.issued_on_hijri && <span className="block text-xs text-ink/50">{c.issued_on_hijri}</span>}
    </span>
  )
}

/** Display title: "شهادة إتمام حفظ — جزء عمّ". */
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

/** Refresh every certificate view after a change (section list, profile tab, profile header). */
export function useInvalidateCertificates() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: ['certificates'] })
    void qc.invalidateQueries({ queryKey: ['student-certificates'] })
    void qc.invalidateQueries({ queryKey: ['student-profile'] })
  }
}

/**
 * Small CSS mock of the printed certificate (gold double frame, title, student, grade).
 * The server renders only PDFs, so the card thumbnail is drawn here.
 */
export function CertificateThumb({ c, className = '' }: { c: Certificate; className?: string }) {
  const { t } = useTranslation('certificates')
  const revoked = c.status === 'revoked'
  const draft = c.status === 'draft'
  return (
    <div aria-hidden className={`relative aspect-[1.414/1] w-full overflow-hidden rounded-lg bg-[#fbf7ee] p-1.5 shadow-inner ${className}`}>
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
          <span dir="auto" className="line-clamp-1 text-[11px] font-semibold leading-tight text-ink">{c.student?.full_name ?? ''}</span>
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
