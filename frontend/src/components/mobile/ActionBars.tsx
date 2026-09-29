import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../Icon'
import { useMobileChrome } from './chrome'

/**
 * Sticky bottom action bar (spec §4.5). Holds the screen's one primary button (flex-1, 48px) and an optional
 * 48px square secondary. It bleeds to the screen edges and sits above the bottom nav when the page has one.
 */
export function StickyActionBar({ children }: { children: ReactNode }) {
  const { bottomNav } = useMobileChrome()
  return (
    <div className={`sticky z-20 -mx-4 mt-6 flex gap-2.5 border-t border-ink/10 bg-white px-4 pb-5 pt-3 sm:-mx-6 sm:px-6 lg:hidden ${bottomNav ? 'bottom-[calc(76px+env(safe-area-inset-bottom))]' : 'bottom-0 pb-[max(1.25rem,env(safe-area-inset-bottom))]'}`}>
      {children}
    </div>
  )
}

/** Square 48px secondary inside the StickyActionBar (icon only, labelled). */
export function StickySquare({ icon, label, to, onClick }: { icon: string; label: string; to?: string; onClick?: () => void }) {
  const cls = 'inline-grid size-12 shrink-0 place-items-center rounded-ctl border border-ink/10 bg-white text-brand-700'
  return to
    ? <Link to={to} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-5" /></Link>
    : <button type="button" onClick={onClick} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-5" /></button>
}

/** Floating "create" button (spec §4.6): one per list page, the page's primary action. */
export function Fab({ label, to, onClick, icon = 'plus' }: { label: string; to?: string; onClick?: () => void; icon?: string }) {
  const { bottomNav } = useMobileChrome()
  const cls = `fixed start-4 z-20 inline-grid size-14 place-items-center rounded-2xl bg-brand-700 text-white shadow-popover lg:hidden ${bottomNav ? 'bottom-[calc(92px+env(safe-area-inset-bottom))]' : 'bottom-6'}`
  return to
    ? <Link to={to} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-6" /></Link>
    : <button type="button" onClick={onClick} aria-label={label} title={label} className={cls}><Icon name={icon} className="size-6" /></button>
}
