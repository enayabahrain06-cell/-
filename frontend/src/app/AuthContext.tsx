import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { authApi, type AuthUser, type TokenResponse } from '../api/auth'
import { setUnauthorizedHandler, tokenStore } from '../api/client'
import { setLocale } from '../lib/i18n'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  signIn: (res: TokenResponse) => void
  signOut: () => Promise<void>
  hasRole: (...roles: string[]) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState<boolean>(() => tokenStore.get() !== null)

  useEffect(() => {
    setUnauthorizedHandler(() => setUser(null))
    if (!tokenStore.get()) return
    authApi
      .me()
      .then(setUser)
      .catch(() => tokenStore.set(null))
      .finally(() => setLoading(false))
  }, [])

  const signIn = useCallback((res: TokenResponse) => {
    tokenStore.set(res.token)
    setUser(res.user)
    if (res.user.locale) setLocale(res.user.locale)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
      /* token may already be invalid */
    }
    tokenStore.set(null)
    setUser(null)
  }, [])

  const hasRole = useCallback((...roles: string[]) => !!user?.roles.some((r) => roles.includes(r)), [user])

  const value = useMemo(() => ({ user, loading, signIn, signOut, hasRole }), [user, loading, signIn, signOut, hasRole])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
