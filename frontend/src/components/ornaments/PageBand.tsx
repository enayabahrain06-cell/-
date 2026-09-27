import type { ReactNode } from 'react'
import OrnamentPattern from './OrnamentPattern'
import OrnamentDivider from './OrnamentDivider'

/** Deep emerald header band with a low-opacity gold girih pattern, for dashboards and home screens. */
export default function PageBand({ title, subtitle, actions }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="relative overflow-hidden rounded-2xl bg-deep px-5 py-6 text-white shadow-sm sm:px-7">
      <OrnamentPattern />
      <div className="relative flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="font-display text-3xl text-gold-300">{title}</h1>
          <OrnamentDivider className="mt-2 text-gold-400" />
          {subtitle && <div className="mt-2 text-sm text-white/85">{subtitle}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </header>
  )
}

/** Page title with the ornamental divider, for light pages. */
export function PageTitle({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={className}>
      <h1 className="font-display text-3xl text-ink">{children}</h1>
      <OrnamentDivider className="mt-2 text-gold-500" />
    </div>
  )
}
