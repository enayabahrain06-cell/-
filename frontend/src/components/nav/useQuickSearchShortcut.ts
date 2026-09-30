import { useEffect } from 'react'

/** Ctrl+K / ⌘K anywhere in the staff shell opens the quick search. */
export default function useQuickSearchShortcut(onOpen: () => void, enabled: boolean) {
  useEffect(() => {
    if (!enabled) return
    const onKey = (e: globalThis.KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); onOpen() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onOpen, enabled])
}
