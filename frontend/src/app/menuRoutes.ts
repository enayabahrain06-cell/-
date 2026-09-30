import { NAV_SECTIONS, type MenuEntry, type NavSection } from './nav'

/**
 * Screens built after the naming step. nav.ts lists every menu entry (unbuilt ones as `soon()`); an entry listed
 * here is switched on with its link and permissions, and its route is registered. Keeping them here means nav.ts
 * (the menu's order and names) is not edited for every new screen.
 */
export const BUILT_ENTRIES: Record<string, Pick<MenuEntry, 'path' | 'permissions'> & Partial<Pick<MenuEntry, 'query' | 'isDefault' | 'prefixes'>>> = {
  // Phase 3, التسجيل
  payment_followup: { path: '/payment-followup', permissions: ['wallets.view'] },
  books: { path: '/books', query: { tab: 'books' }, isDefault: true, permissions: ['books.view', 'books.manage'] },
  books_followup: { path: '/books', query: { tab: 'followup' }, permissions: ['books.view', 'books.manage'] },
  level_distribution: { path: '/level-distribution', permissions: ['distribution.manage'] },
  promote_students: { path: '/promote-students', permissions: ['distribution.manage'] },
  update_level: { path: '/update-level', permissions: ['distribution.manage'] },
  upload_archive: { path: '/archive', query: { tab: 'upload' }, permissions: ['archive.manage'] },
  view_archive: { path: '/archive', query: { tab: 'view' }, isDefault: true, permissions: ['archive.view', 'archive.manage'] },
}

/** Routes of those screens (path → page is mapped in routes.tsx by key). */
export const EXTRA_ROUTES: NavSection[] = [
  // Phase 3, التسجيل
  { key: 'payment_followup', path: '/payment-followup', icon: 'wallet', permissions: ['wallets.view'] },
  { key: 'books', path: '/books', icon: 'lessons', permissions: ['books.view', 'books.manage'] },
  { key: 'level_distribution', path: '/level-distribution', icon: 'students', permissions: ['distribution.manage'] },
  { key: 'promote_students', path: '/promote-students', icon: 'chevron', permissions: ['distribution.manage'] },
  { key: 'update_level', path: '/update-level', icon: 'refresh', permissions: ['distribution.manage'] },
  { key: 'archive', path: '/archive', icon: 'history', permissions: ['archive.view', 'archive.manage'] },
]

/** Every staff route: the core ones in nav.ts plus EXTRA_ROUTES. */
export const ALL_ROUTES: NavSection[] = [...NAV_SECTIONS, ...EXTRA_ROUTES]

/** A menu entry with its build switched on when it appears in BUILT_ENTRIES. */
export function withBuilt(e: MenuEntry): MenuEntry {
  const b = BUILT_ENTRIES[e.key]
  return b ? { ...e, ...b } : e
}
