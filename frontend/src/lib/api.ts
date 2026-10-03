import { clearToken, getToken } from './session'
import type { Locale } from './types'

const BASE = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/+$/, '')

/**
 * Erreur normalisée : `message` vient déjà traduit par l'API
 * (SetLocale lit Accept-Language), `errors` porte le détail de validation.
 */
export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors: Record<string, string[]>
  readonly context: Record<string, unknown> | null
  readonly manualFallback: boolean

  constructor(
    status: number,
    message: string,
    extra: {
      code?: string
      errors?: Record<string, string[]>
      context?: Record<string, unknown> | null
      manualFallback?: boolean
    } = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = extra.code
    this.errors = extra.errors ?? {}
    this.context = extra.context ?? null
    this.manualFallback = extra.manualFallback ?? false
  }
}

/**
 * 401 : la session est perdue, l'application doit revenir à la connexion.
 *
 * T-25 : l'API renvoie un message **explicite et traduit**
 * (`bootstrap/app.php` → `api.errors.session_expired`). Il doit survivre au
 * transport, sinon l'utilisateur est renvoyé vers la connexion sans jamais
 * apprendre pourquoi sa session s'est terminée.
 */
export class SessionExpiredError extends ApiError {
  /**
   * `message` reste vide si l'API n'a rien renvoyé : `errorMessage()` utilise
   * alors son `fallback` local. Le code brut `session_expired` ne doit jamais
   * être affiché tel quel à l'utilisateur.
   */
  constructor(message = '') {
    super(401, message, { code: 'session_expired' })
    this.name = 'SessionExpiredError'
  }
}

/**
 * Reçoit le message 401 traduit par l'API pour que l'écran de connexion
 * puisse expliquer la déconnexion (T-25).
 */
type UnauthorizedHandler = (message: string) => void

let onUnauthorized: UnauthorizedHandler | null = null

/** Branché une fois par AuthProvider : vide le jeton et redirige. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  onUnauthorized = handler
}

/**
 * Lit le corps d'une réponse 401 pour en extraire le message traduit.
 * Un corps absent, non JSON ou vide ne doit jamais faire échouer la
 * déconnexion : on renvoie alors le libellé par défaut.
 */
async function readSessionMessage(response: Response): Promise<string> {
  try {
    const contentType = response.headers.get('content-type') ?? ''
    if (!contentType.includes('application/json')) return ''
    const payload = (await response.json().catch(() => null)) as { message?: unknown } | null
    return typeof payload?.message === 'string' ? payload.message : ''
  } catch {
    return ''
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  /** Pour les envois multipart (fichiers). */
  formData?: FormData
  /** Requêtes publiques : aucun jeton envoyé. */
  anonymous?: boolean
  locale?: Locale
  signal?: AbortSignal
  /** Réponse 204 : aucun corps à décoder. */
  empty?: boolean
}

function buildHeaders(options: RequestOptions): Headers {
  const headers = new Headers()
  headers.set('Accept', 'application/json')

  if (options.locale) {
    headers.set('Accept-Language', options.locale)
  }

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  if (!options.anonymous) {
    const token = getToken()
    if (token) {
      headers.set('Authorization', `Bearer ${token}`)
    }
  }

  return headers
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const url = `${BASE}${path}`

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers: buildHeaders(options),
    body: options.formData ?? (options.body !== undefined ? JSON.stringify(options.body) : undefined),
    signal: options.signal,
    credentials: 'omit',
  })

  if (response.status === 401) {
    const message = await readSessionMessage(response)
    clearToken()
    onUnauthorized?.(message)
    throw new SessionExpiredError(message)
  }

  if (options.empty && response.status === 204) {
    return undefined as T
  }

  const contentType = response.headers.get('content-type') ?? ''
  const isJson = contentType.includes('application/json')
  const payload = isJson ? await response.json().catch(() => null) : null

  if (!response.ok) {
    throw new ApiError(
      response.status,
      (payload?.message as string) || `HTTP ${response.status}`,
      {
        code: payload?.code,
        errors: payload?.errors,
        context: payload?.context,
        manualFallback: Boolean(payload?.manual_fallback),
      },
    )
  }

  return payload as T
}

/**
 * Téléchargement authentifié. L'API répond soit par une URL signée (JSON,
 * stockage S3), soit par le flux du fichier (disque local) : les deux cas
 * aboutissent à un téléchargement déclenché par le navigateur. Le jeton
 * n'apparaît jamais dans l'URL.
 */
async function download(
  path: string,
  fileName: string,
  options: Omit<RequestOptions, 'method' | 'body'> = {},
): Promise<void> {
  const response = await fetch(`${BASE}${path}`, {
    method: 'GET',
    headers: buildHeaders({ ...options, empty: true }),
    credentials: 'omit',
    signal: options.signal,
  })

  if (response.status === 401) {
    const message = await readSessionMessage(response)
    clearToken()
    onUnauthorized?.(message)
    throw new SessionExpiredError(message)
  }

  const contentType = response.headers.get('content-type') ?? ''

  if (!response.ok) {
    const payload = contentType.includes('application/json') ? await response.json().catch(() => null) : null
    throw new ApiError(response.status, (payload?.message as string) || `HTTP ${response.status}`, {
      code: payload?.code,
    })
  }

  if (contentType.includes('application/json')) {
    const payload = (await response.json().catch(() => null)) as { url?: string } | null
    if (payload?.url) {
      window.open(payload.url, '_blank', 'noopener')
      return
    }
  }

  const blob = await response.blob()
  const href = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = href
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(href)
}

export const api = {
  get: <T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'GET' }),

  post: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'POST', body }),

  patch: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'PATCH', body }),

  put: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'PUT', body }),

  delete: <T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'DELETE', empty: true }),

  postForm: <T>(path: string, formData: FormData, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'POST', formData }),

  patchForm: <T>(path: string, formData: FormData, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
    request<T>(path, { ...options, method: 'PATCH', formData }),

  /** URL absolue de l'API, pour les redirections OAuth. */
  absolute: (path: string) => `${BASE}${path}`,

  /** Fichier protégé : flux authentifié, jamais un lien nu. */
  download,
}

export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    /*
     * T-25 : le message « session expirée » est renvoyé par l'API et traduit
     * selon la locale. Le remplacer par le `fallback` local effaçait cette
     * information et laissait l'utilisateur devant un écran de connexion
     * muet. Le `fallback` ne sert que si l'API n'a rien renvoyé.
     */
    return error.message || fallback
  }
  if (error instanceof Error && error.message) {
    return error.message
  }
  return fallback
}

/** Message de la première erreur de validation, s'il y en a une. */
export function firstFieldError(error: unknown, field: string, fallback: string): string | null {
  if (error instanceof ApiError && error.errors[field]?.length) {
    return error.errors[field][0]
  }
  return fallback
}
