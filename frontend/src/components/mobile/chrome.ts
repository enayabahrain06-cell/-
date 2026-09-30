import { createContext, useContext, useMemo } from 'react'
import { useAuth } from '../../app/AuthContext'
import { NAV_SECTIONS, type NavSection } from '../../app/nav'
import { useNavV2 } from '../../app/menuV2'
import { fromOldUrl, tabHref } from '../../app/navV2'

/** Shared state of the mobile shell (see MobileChrome.tsx). */
export interface Crumb { label: string; to?: string }

export interface ChromeState {
  bottomNav: boolean
  slot: HTMLElement | null
  claim: () => () => void
  openMore: () => void
}
export const ChromeContext = createContext<ChromeState>({ bottomNav: false, slot: null, claim: () => () => undefined, openMore: () => undefined })
export const useMobileChrome = () => useContext(ChromeContext)

/** Staff tabs: the spec's الرئيسية · الحضور · الصفوف · الطلبة, filled from these when a permission is missing. */
const PREFERRED = ['dashboard', 'attendance', 'lessons', 'students']
const FILL = ['payments', 'reports', 'messages', 'evaluation', 'teachers']
export const TAB_ICON: Record<string, string> = { dashboard: 'home' }

export function useMobileTabs(): NavSection[] {
  const { can } = useAuth()
  const v2 = useNavV2() === true
  return useMemo(() => {
    const allowed = NAV_SECTIONS.filter((s) => can(...s.permissions))
    const byKey = (k: string) => allowed.find((s) => s.key === k)
    const tabs = PREFERRED.map(byKey).filter(Boolean) as NavSection[]
    for (const k of FILL) {
      if (tabs.length >= 4) break
      const s = byKey(k)
      if (s) tabs.push(s)
    }
    // nav_v2: the same destinations, at their tab URL (its default mode), so the root-tab check still matches.
    if (!v2) return tabs
    return tabs.map((s) => {
      const place = s.path === '/' ? null : fromOldUrl(s.path, new URLSearchParams(), can)
      return place ? { ...s, path: tabHref(place.tab) } : s
    })
  }, [can, v2])
}

