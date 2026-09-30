import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { menuApi, type MenuLayout } from '../api/menu'
import { useAuth } from './AuthContext'
import { MENU, MENU_TOP, entryScore, type MenuEntry, type MenuSectionDef } from './nav'

export interface MenuSectionView { key: string; icon: string; entries: MenuEntry[] }

const EMPTY: MenuLayout = { sections: [], entries: {}, hidden: [] }

/** Put the keys listed in `order` first, in that order; everything else keeps its default place after them. */
function ordered<T extends { key: string }>(items: T[], order: string[] | undefined): T[] {
  if (!order?.length) return items
  const pos = new Map(order.map((k, i) => [k, i]))
  return [...items].sort((a, b) => (pos.get(a.key) ?? order.length + items.indexOf(a)) - (pos.get(b.key) ?? order.length + items.indexOf(b)))
}

/** Apply the saved layout (القائمة) to the full menu; `applyHidden` false keeps hidden entries (for the editor). */
// eslint-disable-next-line react-refresh/only-export-components
export function arrangeMenu(layout: MenuLayout, applyHidden = true): MenuSectionDef[] {
  const hidden = new Set(applyHidden ? layout.hidden : [])
  return ordered(MENU, layout.sections).map((s) => ({
    ...s,
    entries: ordered(s.entries, layout.entries[s.key]).filter((e) => !hidden.has(e.key)),
  }))
}

// eslint-disable-next-line react-refresh/only-export-components
export function useMenuLayout() {
  const { can } = useAuth()
  return useQuery({ queryKey: ['menu-layout'], queryFn: menuApi.get, enabled: can('dashboard.view', 'menu.manage'), staleTime: 5 * 60_000 })
}

/**
 * The menu this user sees: built entries they may open, in the saved order, without hidden ones.
 * Sections with nothing left are dropped. `active` is the key of the entry matching the current page.
 */
// eslint-disable-next-line react-refresh/only-export-components
export function useMenu(): { top: MenuEntry | null; sections: MenuSectionView[]; active: string | null } {
  const { can } = useAuth()
  const layout = useMenuLayout().data ?? EMPTY
  const location = useLocation()

  return useMemo(() => {
    const visible = (e: MenuEntry) => e.path !== null && can(...e.permissions)
    const sections = arrangeMenu(layout)
      .map((s) => ({ key: s.key, icon: s.icon, entries: s.entries.filter(visible) }))
      .filter((s) => s.entries.length > 0)
    const params = new URLSearchParams(location.search)
    let active: string | null = null
    let best = 0
    for (const e of [MENU_TOP, ...sections.flatMap((s) => s.entries)]) {
      const score = entryScore(e, location.pathname, params)
      if (score > best) { best = score; active = e.key }
    }
    return { top: can(...MENU_TOP.permissions) ? MENU_TOP : null, sections, active }
  }, [can, layout, location.pathname, location.search])
}
