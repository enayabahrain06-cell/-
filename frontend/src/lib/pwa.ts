import { useEffect, useState } from 'react'

/**
 * Installable app (PWA) helpers: register the service worker, and keep Chrome's install prompt so the
 * المزيد sheet can offer "install app". iOS has no prompt: there the sheet shows the Share → Add to Home Screen hint.
 */

interface InstallPromptEvent extends Event { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

let deferred: InstallPromptEvent | null = null
const listeners = new Set<() => void>()
const notify = () => listeners.forEach((l) => l())

export function registerPwa() {
  if (typeof window === 'undefined') return
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    deferred = e as InstallPromptEvent
    notify()
  })
  window.addEventListener('appinstalled', () => { deferred = null; notify() })
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      void navigator.serviceWorker.register(import.meta.env.DEV ? '/sw.js?dev=1' : '/sw.js').catch(() => undefined)
    })
  }
}

export const isStandalone = () =>
  typeof window !== 'undefined' && (window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true)

const isIos = () => typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent)

/** 'prompt' (Chrome/Edge/Android can install now), 'ios' (show the Add to Home Screen hint), or null (installed / unsupported). */
export function useInstall(): { mode: 'prompt' | 'ios' | null; install: () => Promise<void> } {
  const [, force] = useState(0)
  useEffect(() => {
    const l = () => force((n) => n + 1)
    listeners.add(l)
    return () => { listeners.delete(l) }
  }, [])
  const mode = isStandalone() ? null : deferred ? 'prompt' : isIos() ? 'ios' : null
  return {
    mode,
    install: async () => {
      if (!deferred) return
      await deferred.prompt()
      await deferred.userChoice.catch(() => undefined)
      deferred = null
      notify()
    },
  }
}
