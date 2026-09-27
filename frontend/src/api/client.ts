import axios, { AxiosError } from 'axios'
import i18n from '../lib/i18n'

const TOKEN_KEY = 'ahl.token'

export const tokenStore = {
  get(): string | null {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set(token: string | null) {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* ignore */
    }
  },
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['Accept-Language'] = i18n.language
  return config
})

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn
}

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    if (error.response?.status === 401 && tokenStore.get()) {
      tokenStore.set(null)
      onUnauthorized?.()
    }
    return Promise.reject(error)
  },
)

export type FieldErrors = Record<string, string[]>

/** Normalise an Axios error into a message plus Laravel field errors. */
export function parseApiError(error: unknown): { message: string; fields: FieldErrors } {
  if (axios.isAxiosError(error)) {
    if (!error.response) return { message: i18n.t('common:errors.network'), fields: {} }
    const data = error.response.data as { message?: string; errors?: FieldErrors } | undefined
    return { message: data?.message || i18n.t('common:errors.unexpected'), fields: data?.errors ?? {} }
  }
  return { message: i18n.t('common:errors.unexpected'), fields: {} }
}
