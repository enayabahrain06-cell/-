import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import Icon from '../../components/Icon'
import { SURFACE } from '../../components/ui'

/** Card shell matching TodayList / AlertsBox: header row with a bottom border, optional footer link. */
export function PanelCard({ id, title, icon, children, footer }: { id: string; title: string; icon: string; children: ReactNode; footer?: { to: string; label: string } }) {
  return (
    <section className={`${SURFACE} @container flex min-w-0 flex-col`} aria-labelledby={id}>
      <h2 id={id} className="flex items-center gap-2 border-b border-ink/8 px-5 py-4 text-base font-semibold text-ink">
        <Icon name={icon} className="size-5 text-brand-700" />
        {title}
      </h2>
      <div className="flex-1">{children}</div>
      {footer && (
        <div className="border-t border-ink/6 px-3 py-2.5">
          <Link to={footer.to} className="flex w-full items-center justify-center gap-1 rounded-lg py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50">
            {footer.label}
            <Icon name="chevron" className="size-4 rtl:rotate-180" />
          </Link>
        </div>
      )}
    </section>
  )
}

export function StatTile({ label, value, hint, tone }: { label: string; value: string; hint?: ReactNode; tone?: 'danger' | 'gold' }) {
  const bg = tone === 'danger' ? 'bg-danger/5 border-danger/15' : tone === 'gold' ? 'bg-gold-500/6 border-gold-500/20' : 'bg-ink/[0.02] border-ink/6'
  return (
    // Narrow card: one row (label + hint | value). Wider card: stacked tile.
    <div className={`flex min-w-0 items-center justify-between gap-3 rounded-xl border px-3 py-2 @sm:flex-col @sm:items-stretch @sm:justify-start @sm:gap-0 @sm:py-2.5 ${bg}`}>
      <div className="min-w-0 @sm:contents">
        <p className="text-xs leading-snug text-ink/60">{label}</p>
        {hint && <p className="mt-0.5 text-xs leading-snug text-ink/55 @sm:order-last">{hint}</p>}
      </div>
      <p className={`shrink-0 truncate text-lg font-semibold tabular-nums @sm:mt-1 @sm:text-xl ${tone === 'danger' ? 'text-danger' : 'text-ink'}`}>{value}</p>
    </div>
  )
}

export function PanelSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-4 p-4" aria-busy="true">
      <div className="grid grid-cols-1 gap-2 @sm:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-20 animate-pulse rounded-xl bg-ink/5" />)}
      </div>
      <div className="space-y-2">
        {Array.from({ length: rows }).map((_, i) => <div key={i} className="h-10 animate-pulse rounded-lg bg-ink/5" />)}
      </div>
    </div>
  )
}

export function PanelError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation('dashboard-panels')
  return (
    <div role="alert" className="m-4 rounded-xl border border-danger/25 bg-danger/5 p-4 text-center text-sm text-danger">
      <p>{t('error')}</p>
      <button type="button" onClick={onRetry} className="mt-2 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-ink shadow-sm">
        {t('retry')}
      </button>
    </div>
  )
}
