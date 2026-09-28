import { test as base } from '@playwright/test'
import { LOCALE_KEY } from './paths'

export type AppLocale = 'ar' | 'en'
export type VisualOptions = { appLocale: AppLocale }

/**
 * Every page starts in the project's locale (`ar` = RTL, `en` = LTR) with motion switched off.
 * The locale goes in localStorage before any app script runs, exactly as the language switcher stores it.
 */
export const test = base.extend<VisualOptions>({
  appLocale: ['ar', { option: true }],
  page: async ({ page, appLocale }, provide) => {
    await page.addInitScript(([key, locale]) => {
      try { localStorage.setItem(key, locale) } catch { /* storage blocked */ }
      // Cumulative layout shift since load, read by the layout checks.
      ;(window as unknown as { __cls: number }).__cls = 0
      try {
        new PerformanceObserver((list) => {
          for (const e of list.getEntries() as (PerformanceEntry & { value: number; hadRecentInput: boolean })[]) {
            if (!e.hadRecentInput) (window as unknown as { __cls: number }).__cls += e.value
          }
        }).observe({ type: 'layout-shift', buffered: true })
      } catch { /* unsupported */ }
    }, [LOCALE_KEY, appLocale] as const)
    await provide(page)
  },
})

export { expect } from '@playwright/test'

/** Kills transitions, animations and the blinking caret so screenshots are stable. */
export const FREEZE_CSS = `
*, *::before, *::after {
  transition: none !important;
  animation: none !important;
  caret-color: transparent !important;
  scroll-behavior: auto !important;
}`
