import { useEffect } from 'react'
import Icon from '../Icon'

/**
 * Save feedback on mobile (spec §5): a toast at the top, under the app bar / page header, gone after 4 seconds.
 * `role="status"` (or `alert` for errors) so screen readers announce it.
 */
export default function MobileToast({ message, tone = 'ok', onDone }: { message: string | null; tone?: 'ok' | 'error'; onDone: () => void }) {
  useEffect(() => {
    if (!message) return
    const id = setTimeout(onDone, 4000)
    return () => clearTimeout(id)
  }, [message, onDone])

  if (!message) return null
  return (
    <div role={tone === 'error' ? 'alert' : 'status'}
      className={`fixed inset-x-4 top-[68px] z-40 flex items-start gap-2 rounded-card border px-4 py-3 text-[15px] shadow-popover lg:hidden ${
        tone === 'error' ? 'border-danger/25 bg-white text-danger' : 'border-brand-600/20 bg-white text-brand-800'
      }`}>
      <Icon name={tone === 'error' ? 'alert' : 'check'} className="mt-0.5 size-5 shrink-0" />
      <p className="min-w-0 flex-1">{message}</p>
    </div>
  )
}
