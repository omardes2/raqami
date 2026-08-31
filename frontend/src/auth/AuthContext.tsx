import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ensureCsrf, setActiveTenant, setApiLocale } from '../lib/api'
import { applyLocale } from '../i18n'
import type { AuthUser } from './types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  login: (email: string, password: string) => Promise<AuthUser>
  register: (input: RegisterInput) => Promise<AuthUser>
  logout: () => Promise<void>
  refresh: () => Promise<AuthUser | null>
  can: (permission: string) => boolean
}

interface RegisterInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  locale: string
}

const AuthContext = createContext<AuthState | null>(null)

function applyUser(user: AuthUser) {
  applyLocale(user.locale)
  setApiLocale(user.locale)
  setActiveTenant(user.active_tenant?.id ?? null)
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get<AuthUser>('/me')
      setUser(data)
      applyUser(data)
      return data
    } catch {
      setUser(null)
      return null
    }
  }, [])

  useEffect(() => {
    let active = true
    const bootstrap = async () => {
      await refresh()
      // setState runs after the await (not synchronously in the effect) and is
      // guarded so an unmount mid-request can't set state on a gone component.
      if (active) setLoading(false)
    }
    void bootstrap()
    return () => {
      active = false
    }
  }, [refresh])

  const login = useCallback(async (email: string, password: string) => {
    await ensureCsrf()
    await api.post('/login', { email, password })
    const me = await refresh()
    if (!me) throw new Error('login failed')
    return me
  }, [refresh])

  const register = useCallback(async (input: RegisterInput) => {
    await ensureCsrf()
    await api.post('/register', input)
    const me = await refresh()
    if (!me) throw new Error('registration failed')
    return me
  }, [refresh])

  const logout = useCallback(async () => {
    await ensureCsrf()
    await api.post('/logout')
    setUser(null)
    setActiveTenant(null)
  }, [])

  const can = useCallback(
    (permission: string) => !!user?.permissions.includes(permission),
    [user],
  )

  const value = useMemo<AuthState>(
    () => ({ user, loading, login, register, logout, refresh, can }),
    [user, loading, login, register, logout, refresh, can],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
