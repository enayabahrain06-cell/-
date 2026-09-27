import { api } from './client'

export type SettingValue = string | number | boolean | null

export interface SettingItem {
  key: string
  type: 'string' | 'int' | 'bool' | 'json'
  value: SettingValue
  editable: boolean
  /** false when the key is seeded but not in the backend registry (shown read-only). */
  known: boolean
  options?: string[]
  min?: number
  max?: number
}

export interface SettingsGroup {
  key: string
  settings: SettingItem[]
}

export interface SettingsPayload {
  groups: SettingsGroup[]
  logo_url: string | null
}

type Saved = { message: string; data: SettingsPayload }

export const settingsApi = {
  get: () => api.get<{ data: SettingsPayload }>('/admin/settings').then((r) => r.data.data),
  update: (settings: Record<string, SettingValue>) =>
    api.put<Saved & { changed: string[] }>('/admin/settings', { settings }).then((r) => r.data),
  uploadLogo: (file: File) => {
    const body = new FormData()
    body.append('logo', file)
    return api.post<Saved>('/admin/settings/logo', body).then((r) => r.data)
  },
  deleteLogo: () => api.delete<Saved>('/admin/settings/logo').then((r) => r.data),
}
