import { useEffect, useId, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import Icon from '../Icon'
import { useScrollLock } from '../useScrollLock'

/**
 * Mobile bottom sheet (spec §4): a modal dialog anchored to the bottom edge, at most 85% of the dynamic viewport,
 * scrolling inside. Escape and the backdrop close it; focus moves in on open and back to the trigger on close.
 */
export default function BottomSheet({ title, open, onClose, children, footer }: { title: string; open: boolean; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  const { t } = useTranslation()
  const titleId = useId()
  const panel = useRef<HTMLDivElement>(null)
  useScrollLock(open)

  useEffect(() => {
    if (!open) return
    const trigger = document.activeElement as HTMLElement | null
    panel.current?.focus()
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      trigger?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button type="button" tabIndex={-1} aria-hidden className="absolute inset-0 bg-ink/50" onClick={onClose} />
      <div ref={panel} tabIndex={-1} className="absolute inset-x-0 bottom-0 flex max-h-[85dvh] flex-col rounded-t-card bg-page shadow-popover focus:outline-none">
        <div className="flex shrink-0 items-center gap-2 border-b border-ink/10 px-4 pb-2 pt-2">
          <span aria-hidden className="absolute inset-x-0 top-1.5 mx-auto h-1 w-10 rounded-full bg-ink/15" />
          <h2 id={titleId} className="min-w-0 flex-1 truncate pt-2 text-lg font-semibold text-ink">{title}</h2>
          <button type="button" onClick={onClose} aria-label={t('close')} className="inline-grid size-11 place-items-center rounded-ctl text-ink/65">
            <Icon name="close" className="size-5" />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">{children}</div>
        {footer && <div className="flex shrink-0 gap-2.5 border-t border-ink/10 bg-white px-4 pb-5 pt-3">{footer}</div>}
      </div>
    </div>
  )
}
