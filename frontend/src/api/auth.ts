import { api } from './client'

export interface AuthUser {
  id: number
  name: string
  phone: string
  email: string | null
  gender: string | null
  locale: 'ar' | 'en'
  is_active: boolean
  track?: 'male' | 'female' | 'both'
  roles: string[]
  permissions: string[]
}

export interface TokenResponse {
  token: string
  user: AuthUser
}

export interface OtpRequestResponse {
  message: string
  phone: string
  expires_at: string
  debug_code?: string
}

export const authApi = {
  login: (phone: string, password: string) =>
    api.post<TokenResponse>('/auth/login', { phone, password, device: 'web' }).then((r) => r.data),
  requestOtp: (phone: string) => api.post<OtpRequestResponse>('/auth/otp/request', { phone }).then((r) => r.data),
  verifyOtp: (phone: string, code: string) =>
    api.post<TokenResponse>('/auth/otp/verify', { phone, code, device: 'web' }).then((r) => r.data),
  me: () => api.get<{ data: AuthUser }>('/auth/me').then((r) => r.data.data),
  logout: () => api.post('/auth/logout'),
  updateLocale: (locale: 'ar' | 'en') => api.put('/auth/locale', { locale }),
}
