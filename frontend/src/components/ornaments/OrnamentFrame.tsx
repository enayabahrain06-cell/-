import type { CSSProperties, ReactNode } from 'react'
import OrnamentSvg from './OrnamentSvg'

const CORNERS = [
  'top-0 start-0 -translate-y-1/3 -translate-x-1/3 rtl:translate-x-1/3',
  'top-0 end-0 -translate-y-1/3 translate-x-1/3 rtl:-translate-x-1/3',
  'bottom-0 start-0 translate-y-1/3 -translate-x-1/3 rtl:translate-x-1/3',
  'bottom-0 end-0 translate-y-1/3 translate-x-1/3 rtl:-translate-x-1/3',
]

/**
 * Decorative frame for certificates, receipts, printed rosters and the honor board podium:
 * a girih band between double rules with khatam medallions in the corners.
 * minimal keeps the rules and medallions; off keeps one plain rule.
 * `paper` is the surface colour (cream by default); text colour sets the ink.
 */
export default function OrnamentFrame({ children, className = 'text-gold-500', paper = 'var(--color-paper)' }: { children: ReactNode; className?: string; paper?: string }) {
  const surface = { backgroundColor: paper } satisfies CSSProperties
  return (
    <div className={`relative m-4 border-2 border-current ${className}`} style={surface}>
      <div aria-hidden className="ornament ornament-full pointer-events-none absolute inset-1.5 overflow-hidden">
        <span className="ornament-pattern ornament-size-28 ornament-opacity-45" />
      </div>
      <div aria-hidden className="ornament pointer-events-none absolute inset-1.5 border border-current/70" />
      <div aria-hidden className="ornament pointer-events-none absolute inset-5 border border-current/70" style={surface} />
      {CORNERS.map((pos) => (
        <span key={pos} aria-hidden className={`ornament absolute ${pos} size-11 rounded-full sm:size-14`} style={surface}>
          <OrnamentSvg name="medallion" className="size-full" />
        </span>
      ))}
      <div className="relative px-8 py-10 sm:px-14 sm:py-12">{children}</div>
    </div>
  )
}
