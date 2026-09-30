import { NAV_SECTIONS, type MenuEntry, type NavSection } from './nav'

/**
 * Screens built after the naming step. nav.ts lists every menu entry (unbuilt ones as `soon()`); an entry listed
 * here is switched on with its link and permissions, and its route is registered. Keeping them here means nav.ts
 * (the menu's order and names) is not edited for every new screen.
 */
export const BUILT_ENTRIES: Record<string, Pick<MenuEntry, 'path' | 'permissions'> & Partial<Pick<MenuEntry, 'query' | 'isDefault' | 'prefixes'>>> = {}

/** Routes of those screens (path → page is mapped in routes.tsx by key). */
export const EXTRA_ROUTES: NavSection[] = []

/** Every staff route: the core ones in nav.ts plus EXTRA_ROUTES. */
export const ALL_ROUTES: NavSection[] = [...NAV_SECTIONS, ...EXTRA_ROUTES]

/** A menu entry with its build switched on when it appears in BUILT_ENTRIES. */
export function withBuilt(e: MenuEntry): MenuEntry {
  const b = BUILT_ENTRIES[e.key]
  return b ? { ...e, ...b } : e
}
