import NAV_MAP from './nav-map.json'

/**
 * nav_v2: sections → tabs → views (a mode, optionally narrowed by a kind). The map is generated from the approved
 * table by scripts/nav-map.mjs (docs/07-NAV-V2.md); this file only reads it. A view shows one of the existing pages
 * (`page`, a route key) with that page's own tab value (`old.query`), so no business logic moves.
 */
export interface V2Old { path: string; query: Record<string, string>; isDefault?: boolean; prefixes?: string[] }
export interface V2View {
  id: string
  feature?: string
  extra?: string
  name: { ar: string; en: string }
  mode: string
  kind?: string
  permissions: string[]
  page: string
  old: V2Old
}
export interface V2Tab { key: string; path: string; kindStyle?: 'button'; views: V2View[] }
export interface V2Section { key: string; path: string; icon: string; tabs: V2Tab[] }

export const V2_SECTIONS = NAV_MAP.sections as V2Section[]

type Can = (...perms: string[]) => boolean

/** A tab's views the user may open, and whether the tab is hidden in القائمة (every feature it carries is hidden). */
export const visibleViews = (tab: V2Tab, can: Can) => tab.views.filter((v) => can(...v.permissions))
export function tabHidden(tab: V2Tab, hidden: Set<string>): boolean {
  const features = tab.views.filter((v) => v.feature).map((v) => v.feature as string)
  return features.length > 0 && features.every((f) => hidden.has(f))
}

/** The distinct modes of a set of views, in order; and the views of one mode (its kinds). */
export const modesOf = (views: V2View[]) => [...new Set(views.map((v) => v.mode))]
export const kindsOf = (views: V2View[], mode: string) => views.filter((v) => v.mode === mode)

/** URL of a tab, with the mode and kind when the tab has more than one of them. */
export function tabHref(tab: V2Tab, view?: V2View, extra?: URLSearchParams): string {
  const q = new URLSearchParams(extra)
  q.delete('mode'); q.delete('kind')
  if (view && modesOf(tab.views).length > 1) q.set('mode', view.mode)
  if (view?.kind && kindsOf(tab.views, view.mode).length > 1) q.set('kind', view.kind)
  const s = q.toString()
  return `${tab.path}${s ? `?${s}` : ''}`
}

/**
 * Which view of the tab the URL asks for: ?mode= and ?kind= among the views the user may open; otherwise the first
 * mode (and kind) they may open, which is the default mode for their role.
 */
export function pickView(views: V2View[], params: URLSearchParams): V2View | undefined {
  const mode = params.get('mode')
  const inMode = views.filter((v) => v.mode === mode)
  const pool = inMode.length ? inMode : views.filter((v) => v.mode === views[0]?.mode)
  return pool.find((v) => v.kind && v.kind === params.get('kind')) ?? pool[0]
}

/** How well an old URL matches a view (0 = not at all), the same scoring as the old menu's entryScore. */
function oldScore(o: V2Old, pathname: string, params: URLSearchParams): number {
  if (pathname !== o.path) return 0
  const query = Object.entries(o.query)
  if (query.length === 0) return 3
  if (query.every(([k, val]) => (params.get(k) ?? '') === val)) return 4
  if (o.isDefault && query.every(([k]) => !params.get(k))) return 3
  // Same page, another value of its own key (a report key, say): that tab still owns it.
  return query.some(([k, val]) => val === '' && params.get(k)) ? 2 : 1
}

export interface V2Place { section: V2Section; tab: V2Tab; view: V2View }

/** The tab and view an old (flag-off) URL now lives on, among views the user may open. Only exact old paths. */
export function fromOldUrl(pathname: string, params: URLSearchParams, can: Can): V2Place | null {
  let best: V2Place | null = null
  let score = 0
  for (const section of V2_SECTIONS) for (const tab of section.tabs) for (const view of tab.views) {
    if (!can(...view.permissions)) continue
    const s = oldScore(view.old, pathname, params)
    if (s > score) { score = s; best = { section, tab, view } }
  }
  return best
}

/** New URL for an old one: its tab and mode, keeping every other query value (filters, ids) but the old tab key. */
export function redirectOf(place: V2Place, params: URLSearchParams): string {
  const rest = new URLSearchParams(params)
  for (const k of Object.keys(place.view.old.query)) if (place.view.old.query[k] !== '') rest.delete(k)
  return tabHref(place.tab, place.view, rest)
}

/** Old (flag-off) URL of a view: its page with its own tab value, keeping the other query values. */
export function oldHref(view: V2View, params: URLSearchParams): string {
  const q = new URLSearchParams(params)
  q.delete('mode'); q.delete('kind')
  for (const [k, val] of Object.entries(view.old.query)) if (val !== '') q.set(k, val)
  const s = q.toString()
  return `${view.old.path}${s ? `?${s}` : ''}`
}

/** The section a location belongs to: a new tab URL, or an old page / detail page under one of its views. */
export function sectionOfPath(pathname: string): V2Section | undefined {
  const direct = V2_SECTIONS.find((s) => pathname === s.path || pathname.startsWith(`${s.path}/`))
  if (direct && direct.tabs.some((t) => pathname === t.path || pathname === direct.path)) return direct
  let best: V2Section | undefined
  let len = 0
  for (const s of V2_SECTIONS) for (const t of s.tabs) for (const v of t.views) {
    for (const p of [v.old.path, ...(v.old.prefixes ?? [])]) {
      if (p !== '/' && (pathname === p || pathname.startsWith(`${p}/`)) && p.length > len) { len = p.length; best = s }
    }
  }
  return best ?? direct
}

/** A section's tabs in the saved order (القائمة), then any tab the saved order does not know. */
export function orderedTabs(section: V2Section, order: string[] | undefined): V2Tab[] {
  if (!order?.length) return section.tabs
  const pos = new Map(order.map((k, i) => [k, i]))
  return [...section.tabs].sort((a, b) => (pos.get(a.key) ?? order.length + section.tabs.indexOf(a)) - (pos.get(b.key) ?? order.length + section.tabs.indexOf(b)))
}

export const viewName = (v: V2View, lang: string) => (lang.startsWith('ar') ? v.name.ar : v.name.en)
