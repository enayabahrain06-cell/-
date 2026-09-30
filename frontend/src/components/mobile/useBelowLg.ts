import { useSyncExternalStore } from 'react'

/**
 * True below the lg breakpoint (1024px). For mobile-only data (a request only the lg:hidden variant shows), so the
 * desktop layout never pays for it. Layout itself stays in CSS (`lg:hidden` / `hidden lg:block`).
 */
const QUERY = '(max-width: 1023.98px)'
const subscribe = (cb: () => void) => {
  const m = window.matchMedia(QUERY)
  m.addEventListener('change', cb)
  return () => m.removeEventListener('change', cb)
}
export function useBelowLg(): boolean {
  return useSyncExternalStore(subscribe, () => window.matchMedia(QUERY).matches, () => false)
}
