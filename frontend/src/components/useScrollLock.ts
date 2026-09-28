import { useEffect } from 'react'

let locks = 0
let saved = ''

/**
 * Stops the page behind a modal layer (drawer, dialog) from scrolling while `active` is true.
 * Counted, so a dialog opened over another dialog does not unlock the page when it closes.
 */
export function useScrollLock(active: boolean) {
  useEffect(() => {
    if (!active) return
    const root = document.documentElement
    if (locks++ === 0) {
      saved = root.style.overflow
      root.style.overflow = 'hidden'
    }
    return () => {
      if (--locks === 0) root.style.overflow = saved
    }
  }, [active])
}
