import { createContext, useContext } from 'react'

/**
 * nav_v2 hosts the existing pages inside a tab page (features/navV2/TabPage). The host tells the page which of its
 * own tabs to show (`params`, e.g. { tab: 'upload' }) and gives it slots in the tab page's banner, so the page's
 * PageBand and MobilePage add their actions there instead of drawing a second header. Outside nav_v2 there is no
 * host and every page behaves exactly as before.
 */
export interface EmbedHost {
  params: Record<string, string>
  bannerActions: HTMLElement | null
  bannerSubtitle: HTMLElement | null
  mobileActions: HTMLElement | null
  /** Open the tab that shows `page` with that own-tab `query`, keeping `extra` (for example an activity id). */
  go: (page: string, query: Record<string, string>, extra?: Record<string, string>) => void
}

export const EmbedContext = createContext<EmbedHost | null>(null)

/** The nav_v2 host of this page, or null when the page is on its own (old menu). */
export const useEmbed = () => useContext(EmbedContext)

/**
 * A page's own tab value: the host's when nav_v2 shows the page inside a tab, else the URL's. Use it wherever a page
 * reads the query key its in-page tab bar writes (tab, view, group, report).
 */
export function useOwnParam(params: URLSearchParams, key: string): string | null {
  const host = useEmbed()
  return host && key in host.params ? host.params[key] : params.get(key)
}
