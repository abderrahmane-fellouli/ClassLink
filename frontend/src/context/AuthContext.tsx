import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { api, setUnauthorizedHandler } from '../lib/api'
import { auth as authApi, type AuthResult } from '../lib/endpoints'
import { clearToken, getToken, setToken } from '../lib/session'
import { useI18n } from '../i18n'
import type { ApiUser, Locale, Role } from '../lib/types'

export type Status = 'loading' | 'authenticated' | 'anonymous'

interface AuthValue {
  status: Status
  user: ApiUser | null
  token: string | null
  /** Rôle effectif ; `pending` et `denied` ne donnent accès à rien. */
  role: Role | null
  signInWithResult: (result: AuthResult) => void
  signInWithOtp: (email: string, code: string) => Promise<ApiUser>
  signInWithDevRole: (role: 'student' | 'teacher' | 'admin') => Promise<ApiUser>
  microsoftRedirectUrl: () => string
  adoptCallbackToken: (token: string) => Promise<ApiUser | null>
  signOut: () => Promise<void>
  refresh: () => Promise<void>
  updateProfile: (payload: { display_name?: string; locale?: Locale }) => Promise<ApiUser>
  /**
   * T-25 : message 401 renvoyé par l'API lors d'une session expirée, à
   * afficher sur l'écran de connexion. `null` si la session n'a pas été
   * interrompue par le serveur.
   */
  sessionNotice: string | null
  /** RG-01 / §17.6 : rôle `denied` → écran d'accès refusé. */
  isDenied: boolean
  isPending: boolean
}

const AuthContext = createContext<AuthValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const { locale, setLocale } = useI18n()
  const [status, setStatus] = useState<Status>(() => (getToken() ? 'loading' : 'anonymous'))
  const [user, setUser] = useState<ApiUser | null>(null)
  const [token, setTokenState] = useState<string | null>(() => getToken())
  const [sessionNotice, setSessionNotice] = useState<string | null>(null)

  const signInWithResult = useCallback(
    (result: AuthResult) => {
      setToken(result.token)
      setTokenState(result.token)
      setUser(result.user)
      if (result.user.locale) setLocale(result.user.locale)
      setStatus('authenticated')
      // La reconnexion réussie efface le message de session expirée.
      setSessionNotice(null)
    },
    [setLocale],
  )

  const signOut = useCallback(async () => {
    try {
      await authApi.logout({ locale })
    } catch {
      /* la session locale est fermée quoi qu'il arrive */
    }
    clearToken()
    setTokenState(null)
    setUser(null)
    setStatus('anonymous')
  }, [locale])

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setStatus('anonymous')
      setUser(null)
      return
    }

    try {
      const me = await api.get<ApiUser>('/me', { locale })
      setUser(me)
      setStatus('authenticated')
    } catch {
      clearToken()
      setTokenState(null)
      setUser(null)
      setStatus('anonymous')
    }
  }, [locale])

  /* Reconnexion au chargement si un jeton existe en sessionStorage. */
  useEffect(() => {
    if (getToken()) {
      void refresh()
    }
  }, [refresh])

  /* Un 401 quelles que soit sa provenance invalide la session. */
  useEffect(() => {
    setUnauthorizedHandler((message: string) => {
      clearToken()
      setTokenState(null)
      setUser(null)
      setStatus('anonymous')
      // T-25 : le message traduit par l'API est transmis à l'écran de
      // connexion, sinon l'utilisateur est déconnecté sans explication.
      setSessionNotice(message)
    })
    return () => setUnauthorizedHandler(null)
  }, [])

  const signInWithOtp = useCallback(
    async (email: string, code: string) => {
      const result = await authApi.verifyOtp(email, code, { locale })
      signInWithResult(result)
      return result.user
    },
    [locale, signInWithResult],
  )

  const signInWithDevRole = useCallback(
    async (role: 'student' | 'teacher' | 'admin') => {
      const result = await authApi.devLogin(role, { locale })
      signInWithResult(result)
      return result.user
    },
    [locale, signInWithResult],
  )

  /** §17.6 : le callback Microsoft renvoie `#token=...`. */
  const adoptCallbackToken = useCallback(
    async (incoming: string) => {
      setToken(incoming)
      setTokenState(incoming)
      try {
        const me = await api.get<ApiUser>('/me', { locale })
        setUser(me)
        if (me.locale) setLocale(me.locale)
        setStatus('authenticated')
        return me
      } catch {
        clearToken()
        setTokenState(null)
        setUser(null)
        setStatus('anonymous')
        return null
      }
    },
    [locale, setLocale],
  )

  const updateProfile = useCallback(
    async (payload: { display_name?: string; locale?: Locale }) => {
      const updated = await api.patch<ApiUser>('/me', payload, { locale })
      setUser(updated)
      if (updated.locale && updated.locale !== locale) setLocale(updated.locale)
      return updated
    },
    [locale, setLocale],
  )

  const value = useMemo<AuthValue>(
    () => ({
      status,
      user,
      token,
      role: user?.role ?? null,
      signInWithResult,
      signInWithOtp,
      signInWithDevRole,
      microsoftRedirectUrl: authApi.microsoftRedirectUrl,
      adoptCallbackToken,
      signOut,
      refresh,
      updateProfile,
      sessionNotice,
      isDenied: user?.role === 'denied',
      isPending: user?.role === 'pending',
    }),
    [
      status,
      user,
      token,
      signInWithResult,
      signInWithOtp,
      signInWithDevRole,
      adoptCallbackToken,
      signOut,
      refresh,
      updateProfile,
      sessionNotice,
    ],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth doit être utilisé dans un AuthProvider.')
  }
  return context
}
