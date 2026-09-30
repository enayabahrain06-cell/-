import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../Icon'

/**
 * Mobile atoms (mobile-redesign-spec.md §4.7). Used only inside `lg:hidden` variants: desktop keeps components/ui.tsx.
 * Type floor on mobile: body 15px, captions 13px, labels 12px; muted text never lighter than ink/65.
 */

export const M_CARD = 'rounded-card border border-ink/10 bg-white shadow-card'

export function MCard({ children, className = '', as: Tag = 'section' }: { children: ReactNode; className?: string; as?: 'section' | 'div' | 'article' | 'li' }) {
  return <Tag className={`${M_CARD} p-4 ${className}`}>{children}</Tag>
}

/** Section heading row: 18px title, optional "all" link on the end side. */
export function MSection({ title, action, children, className = '' }: { title: string; action?: { label: string; to: string }; children: ReactNode; className?: string }) {
  return (
    <section className={`space-y-3 ${className}`}>
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-ink">{title}</h2>
        {action && <Link to={action.to} className="-me-2 inline-flex min-h-11 items-center px-2 text-[13px] font-semibold text-info">{action.label}</Link>}
      </div>
      {children}
    </section>
  )
}

export type PillTone = 'ok' | 'warn' | 'err' | 'info' | 'neutral'
const PILL: Record<PillTone, string> = {
  ok: 'bg-brand-50 text-brand-700',
  warn: 'bg-gold-500/12 text-gold-700',
  err: 'bg-danger/10 text-danger',
  info: 'bg-info/10 text-info',
  neutral: 'bg-ink/5 text-ink/65',
}
export function Pill({ tone = 'neutral', children, className = '' }: { tone?: PillTone; children: ReactNode; className?: string }) {
  return <span className={`inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-semibold tabular-nums ${PILL[tone]} ${className}`}>{children}</span>
}

/** Horizontal chip row; the chips read and write the same query params as the desktop filters. */
export function ChipRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div role="group" aria-label={label} className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 py-1.5">
      {children}
    </div>
  )
}
export function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    // 32px visual chip inside a 44px hit area (py-1.5 on the row + the chip's own height).
    <button type="button" aria-pressed={active} onClick={onClick}
      className={`relative inline-flex h-8 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 text-[13px] font-medium tabular-nums transition before:absolute before:-inset-y-1.5 before:inset-x-0 ${
        active ? 'border-brand-700 bg-brand-700 text-white' : 'border-ink/10 bg-white text-ink'
      }`}>
      {children}
    </button>
  )
}

/** Underline tab strip / segmented control for mobile. */
export function MSegmented<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <div role="tablist" aria-label={label} className="flex gap-[3px] rounded-ctl bg-ink/5 p-[3px]">
      {options.map((o) => {
        const active = o.value === value
        return (
          <button key={o.value} type="button" role="tab" aria-selected={active} onClick={() => onChange(o.value)}
            className={`relative min-w-0 flex-1 truncate rounded-lg px-2 text-[13px] font-semibold transition h-9 before:absolute before:-inset-y-1 before:inset-x-0 ${active ? 'bg-white text-brand-700 shadow-card' : 'text-ink/65'}`}>
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

/** List container and rows (tables become these below lg). */
export function MList({ children, label, className = '' }: { children: ReactNode; label?: string; className?: string }) {
  return <ul aria-label={label} className={`divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white shadow-card ${className}`}>{children}</ul>
}

/** One list row: leading element, title + caption, trailing element, chevron when it links. */
export function MRow({ to, leading, title, caption, trailing, className = '' }: { to?: string; leading?: ReactNode; title: ReactNode; caption?: ReactNode; trailing?: ReactNode; className?: string }) {
  const body = (
    <>
      {leading}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[15px] font-semibold text-ink"><bdi>{title}</bdi></span>
        {caption && <span className="mt-0.5 block truncate text-[13px] text-ink/65">{caption}</span>}
      </span>
      {trailing}
      {to && <Icon name="chevron" className="size-4 shrink-0 text-ink/40 rtl:rotate-180" />}
    </>
  )
  const cls = `flex min-h-16 items-center gap-3 px-4 py-2.5 ${className}`
  return <li>{to ? <Link to={to} className={`${cls} active:bg-brand-50/60`}>{body}</Link> : <div className={cls}>{body}</div>}</li>
}

/** 40px initials circle (a photo when there is one). */
export function MAvatar({ name, src, size = 40, className = '' }: { name: string; src?: string | null; size?: 34 | 36 | 40 | 44 | 52 | 56; className?: string }) {
  const px = { 34: 'size-[34px]', 36: 'size-9', 40: 'size-10', 44: 'size-11', 52: 'size-[52px]', 56: 'size-14' }[size]
  const initials = name.trim().split(/\s+/).slice(0, 2).map((w) => w.charAt(0)).join(' ')
  if (src) return <img src={src} alt="" className={`${px} shrink-0 rounded-full object-cover ${className}`} loading="lazy" />
  return <span aria-hidden className={`${px} inline-grid shrink-0 place-items-center rounded-full bg-brand-50 text-[13px] font-semibold text-brand-700 ${className}`}>{initials}</span>
}

/** Designed empty state: icon, one sentence, optional secondary action. */
export function MEmpty({ icon = 'students', text, action }: { icon?: string; text: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-3 px-4 py-6 text-center">
      <span className="inline-grid size-10 place-items-center rounded-full bg-brand-50 text-brand-700"><Icon name={icon} className="size-5" /></span>
      <p className="text-[15px] text-ink/65">{text}</p>
      {action}
    </div>
  )
}

export function Skeleton({ className = '' }: { className?: string }) {
  return <span aria-hidden className={`block animate-pulse rounded-md bg-ink/5 ${className}`} />
}

/** Skeleton rows in the same shape as MRow. */
export function MListSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <ul aria-hidden className="divide-y divide-ink/10 overflow-hidden rounded-card border border-ink/10 bg-white">
      {Array.from({ length: rows }, (_, i) => (
        <li key={i} className="flex min-h-16 items-center gap-3 px-4 py-2.5">
          <Skeleton className="size-10 rounded-full" />
          <span className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /></span>
        </li>
      ))}
    </ul>
  )
}

/** Secondary and primary button looks for mobile (one primary per screen). */
export const M_BTN_PRIMARY = 'inline-flex h-12 items-center justify-center gap-2 rounded-ctl bg-brand-700 px-4 text-[15px] font-semibold text-white disabled:opacity-60'
export const M_BTN_SECONDARY = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-ctl border border-ink/10 bg-white px-4 text-[15px] font-semibold text-brand-700 disabled:opacity-60'

/** Mobile search box (44px). */
export function MSearch({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="flex h-11 items-center gap-2 rounded-ctl border border-ink/10 bg-white px-3 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500">
      <Icon name="search" className="size-5 shrink-0 text-ink/65" />
      <span className="sr-only">{label}</span>
      <input type="search" value={value} onChange={(e) => onChange(e.target.value)} placeholder={label}
        className="min-w-0 flex-1 bg-transparent text-[15px] text-ink placeholder:text-ink/65 focus:outline-none" />
    </label>
  )
}

/** Mobile select (48px), label 13px above; used in filter sheets and forms below lg. */
export function MSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[] }) {
  return (
    <label className="block min-w-0">
      <span className="mb-1.5 block text-[13px] font-medium text-ink/75">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)}
        className="h-12 w-full rounded-md border border-ink/10 bg-white px-3.5 text-[15px] text-ink">
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  )
}
