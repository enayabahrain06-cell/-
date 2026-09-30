import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { PortalCard } from '../../api/portal'
import { Chip, ChipRow, MAvatar, Skeleton } from '../../components/mobile/atoms'
import { formatDate, formatHijri } from '../../lib/format'
import { firstName } from './hooks'

/** Child chips for family-wide pages; hidden for a single child. */
export function ChildChips({ cards, selected, onSelect }: { cards: PortalCard[]; selected: number | null; onSelect: (id: number | null) => void }) {
  const { t } = useTranslation('portal')
  if (cards.length < 2) return null
  return (
    <ChipRow label={t('filter_child')}>
      <Chip active={selected === null} onClick={() => onSelect(null)}>{t('all_children')}</Chip>
      {cards.map((c) => (
        <Chip key={c.student.id} active={selected === c.student.id} onClick={() => onSelect(c.student.id)}>
          <bdi>{firstName(c.student.full_name)}</bdi>
        </Chip>
      ))}
    </ChipRow>
  )
}

/** One line: Gregorian · Hijri. */
export function DualDate({ value, className = '' }: { value: string; className?: string }) {
  const { i18n } = useTranslation()
  const locale = i18n.language
  return <span className={`tabular-nums ${className}`}>{formatDate(value, locale, { weekday: 'short', day: 'numeric', month: 'short' })} · {formatHijri(value, locale)}</span>
}

/** Compact KPI inside a card: value (18px) over a 12px label. */
export function MiniKpi({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0 rounded-ctl bg-page px-2 py-2 text-center">
      <p className="truncate text-lg font-semibold tabular-nums text-ink">{value}</p>
      <p className="truncate text-xs font-semibold text-ink/65">{label}</p>
    </div>
  )
}

/** KPI tile for detail pages: 12px label, 28px value, optional 13px caption. */
export function KpiTile({ label, value, sub, compact = false }: { label: string; value: ReactNode; sub?: ReactNode; compact?: boolean }) {
  return (
    <div className="min-w-0 rounded-card border border-ink/10 bg-white p-4 shadow-card">
      <p className="truncate text-xs font-semibold text-ink/65">{label}</p>
      <p className={`mt-1 truncate font-semibold tabular-nums text-ink ${compact ? 'text-xl leading-8 sm:text-[28px] sm:leading-9' : 'text-[28px] leading-9'}`}>{value}</p>
      {sub && <p className="mt-0.5 truncate text-[13px] text-ink/65">{sub}</p>}
    </div>
  )
}

/** Student identity line for inner pages: avatar, name, halaqa · teacher. */
export function StudentStrip({ card }: { card: PortalCard }) {
  const s = card.student
  return (
    <div className="flex min-w-0 items-center gap-3">
      <MAvatar name={s.full_name} src={s.photo_url} size={44} />
      <div className="min-w-0">
        <p className="truncate text-[15px] font-semibold text-ink"><bdi>{s.full_name}</bdi></p>
        {card.circle && <p className="truncate text-[13px] text-ink/65"><bdi>{card.circle.name}</bdi>{card.circle.teacher && <> · <bdi>{card.circle.teacher}</bdi></>}</p>}
      </div>
    </div>
  )
}

export function CardSkeleton({ lines = 3 }: { lines?: number }) {
  return (
    <div aria-hidden className="space-y-3 rounded-card border border-ink/10 bg-white p-4 shadow-card">
      <div className="flex items-center gap-3"><Skeleton className="size-[52px] rounded-full" /><span className="flex-1 space-y-2"><Skeleton className="h-4 w-1/2" /><Skeleton className="h-3 w-2/3" /></span></div>
      {Array.from({ length: lines }, (_, i) => <Skeleton key={i} className="h-10 w-full" />)}
    </div>
  )
}

/** Error card with a retry button (secondary). */
export function PortalError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation('portal')
  return (
    <div role="alert" className="flex flex-col items-center gap-3 rounded-card border border-danger/25 bg-white px-4 py-6 text-center shadow-card">
      <p className="text-[15px] text-danger">{t('error')}</p>
      <button type="button" onClick={onRetry} className="inline-flex min-h-11 items-center justify-center rounded-ctl border border-ink/10 bg-white px-4 text-[15px] font-semibold text-brand-700">{t('retry')}</button>
    </div>
  )
}
