import { api } from './client'
import type { Paginated } from './students'

export type Gender = 'male' | 'female'
export type Track = 'male' | 'female' | 'both'
export const STAFF_ROLES = ['super_admin', 'supervisor', 'teacher'] as const

export interface AdminUser {
  id: number
  name: string
  phone: string
  email: string | null
  gender: Gender | null
  track: Track
  locale: 'ar' | 'en' | null
  is_active: boolean
  roles: string[]
  teacher: { id: number; gender: Gender | null; specialization: string | null } | null
  last_login_at: string | null
  created_at: string | null
}

export interface UserFilters {
  search?: string
  role?: string
  active?: '1' | '0'
  page?: number
}

export interface UserPayload {
  name: string
  phone: string
  email?: string | null
  password?: string
  gender?: Gender | null
  track?: Track | null
  locale?: 'ar' | 'en'
  roles: string[]
  teacher?: { gender?: Gender | null; specialization?: string | null }
}

export interface RoleRow {
  id: number
  name: string
  label: string
  permissions: string[]
}

export interface PermissionMatrix {
  roles: RoleRow[]
  permissions: Record<string, { name: string; label: string }[]>
}

export const usersApi = {
  list: (f: UserFilters) =>
    api.get<Paginated<AdminUser>>('/admin/users', { params: { per_page: 20, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '')) } }).then((r) => r.data),
  create: (p: UserPayload) => api.post<{ data: AdminUser }>('/admin/users', p).then((r) => r.data.data),
  update: (id: number, p: Partial<UserPayload> & { is_active?: boolean }) => api.put<{ data: AdminUser }>(`/admin/users/${id}`, p).then((r) => r.data.data),
  deactivate: (id: number) => api.delete(`/admin/users/${id}`),
  roles: () => api.get<PermissionMatrix>('/admin/roles').then((r) => r.data),
  savePermissions: (roleId: number, permissions: string[]) => api.put(`/admin/roles/${roleId}/permissions`, { permissions }),
}
