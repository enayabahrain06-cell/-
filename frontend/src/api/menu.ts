import { api } from './client'

/** The shared menu layout (القائمة): section order, entry order per section, hidden entry keys. */
export interface MenuLayout {
  sections: string[]
  entries: Record<string, string[]>
  hidden: string[]
}

export const menuApi = {
  get: () => api.get<{ data: MenuLayout }>('/menu-layout').then((r) => r.data.data),
  save: (d: MenuLayout) => api.put<{ message: string; data: MenuLayout }>('/menu-layout', d).then((r) => r.data),
}
