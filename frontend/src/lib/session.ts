import type { Locale } from './types'

const TOKEN_KEY = 'classlink.token'
const LOCALE_KEY = 'classlink.locale'

/**
 * RG-19 : le jeton vit 8 h côté serveur. Le frontend le conserve en
 * `sessionStorage` uniquement (jamais en localStorage, jamais dans un cookie
 * lisible par un tiers, jamais dans l'URL).
 */
export function getToken(): string | null {
  try {
    return window.sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string): void {
  try {
    window.sessionStorage.setItem(TOKEN_KEY, token)
  } catch {
    /* stockage indisponible : la session reste en mémoire */
  }
}

export function clearToken(): void {
  try {
    window.sessionStorage.removeItem(TOKEN_KEY)
  } catch {
    /* ignoré */
  }
}

export function getStoredLocale(): Locale | null {
  try {
    const value = window.localStorage.getItem(LOCALE_KEY)
    return value === 'fr' || value === 'en' ? value : null
  } catch {
    return null
  }
}

export function setStoredLocale(locale: Locale): void {
  try {
    window.localStorage.setItem(LOCALE_KEY, locale)
  } catch {
    /* ignoré */
  }
}
