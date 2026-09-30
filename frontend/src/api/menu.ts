import { api } from './client'

/**
 * The shared menu layout (القائمة): section order, entry order per section, hidden entry keys; for nav_v2 also the
 * tab order per section and the runtime switch between the old menu and nav_v2 (`nav_v2`, off by default).
 */
export interface MenuLayout {
  sections: string[]
  entries: Record<string, string[]>
  hidden: string[]
  tabs?: Record<string, string[]>
  nav_v2?: boolean
}

export const menuApi = {
  get: () => api.get<{ data: MenuLayout }>('/menu-layout').then((r) => r.data.data),
  save: (d: MenuLayout) => api.put<{ message: string; data: MenuLayout }>('/menu-layout', d).then((r) => r.data),
}
