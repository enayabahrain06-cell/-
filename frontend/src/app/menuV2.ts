import { useMemo } from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from './AuthContext'
import { useMenuLayout } from './menu'
import { V2_SECTIONS, orderedTabs, sectionOfPath, tabHidden, tabHref, visibleViews, type V2Section, type V2Tab, type V2View } from './navV2'

/**
 * nav_v2 switch (القائمة, runtime, off by default). `undefined` while the layout is loading, so the shell can wait
 * instead of flashing the wrong menu; false when this user cannot read the layout at all.
 */
export function useNavV2(): boolean | undefined {
  const { can } = useAuth()
  const q = useMenuLayout()
  if (!can('dashboard.view', 'menu.manage')) return false
  if (q.isLoading) return undefined
  return q.data?.nav_v2 === true
}

export interface V2TabView { tab: V2Tab; views: V2View[]; href: string }
export interface V2SectionView { section: V2Section; tabs: V2TabView[]; href: string }

/**
 * What this user sees in nav_v2: sections in the saved order, their tabs in the saved order, without tabs hidden in
 * القائمة or with no view the user may open, and without sections left empty. `active` is the section of the page.
 */
export function useMenuV2(): { sections: V2SectionView[]; active: string | null } {
  const { can } = useAuth()
  const layout = useMenuLayout().data
  const { pathname } = useLocation()

  return useMemo(() => {
    const hidden = new Set(layout?.hidden ?? [])
    const order = layout?.sections ?? []
    const pos = new Map(order.map((k, i) => [k, i]))
    const sorted = [...V2_SECTIONS].sort((a, b) => (pos.get(a.key) ?? order.length + V2_SECTIONS.indexOf(a)) - (pos.get(b.key) ?? order.length + V2_SECTIONS.indexOf(b)))
    const sections = sorted
      .map((section) => {
        const tabs = orderedTabs(section, layout?.tabs?.[section.key])
          .filter((tab) => !tabHidden(tab, hidden))
          .map((tab) => ({ tab, views: visibleViews(tab, can) }))
          .filter((t) => t.views.length > 0)
          .map((t) => ({ ...t, href: tabHref(t.tab) }))
        return { section, tabs, href: tabs[0]?.href ?? section.path }
      })
      .filter((s) => s.tabs.length > 0)
    return { sections, active: sectionOfPath(pathname)?.key ?? null }
  }, [can, layout, pathname])
}
