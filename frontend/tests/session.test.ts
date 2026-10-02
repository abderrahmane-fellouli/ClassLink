import { describe, expect, it } from 'vitest'
import { clearToken, getStoredLocale, getToken, setStoredLocale, setToken } from '../src/lib/session'

/**
 * RG-19 — le jeton ne doit jamais quitter `sessionStorage` : il ne doit
 * donc pas se retrouver dans `localStorage`, qui survit a la fermeture
 * de l'onglet.
 */
describe('session', () => {
  it('stores the token in sessionStorage only', () => {
    setToken('jeton-abc')

    expect(getToken()).toBe('jeton-abc')
    expect(window.sessionStorage.getItem('classlink.token')).toBe('jeton-abc')
    expect(window.localStorage.getItem('classlink.token')).toBeNull()
  })

  it('clears the token', () => {
    setToken('jeton-abc')
    clearToken()

    expect(getToken()).toBeNull()
  })

  it('reports no token on a fresh session', () => {
    expect(getToken()).toBeNull()
  })

  it('persists the locale in localStorage', () => {
    setStoredLocale('en')

    expect(getStoredLocale()).toBe('en')
    expect(window.localStorage.getItem('classlink.locale')).toBe('en')
  })

  it('rejects an unsupported stored locale', () => {
    window.localStorage.setItem('classlink.locale', 'ar')

    // L'arabe n'est pas une langue proposee : la valeur est rejetee.
    expect(getStoredLocale()).toBeNull()
  })

  it('keeps working when storage throws', () => {
    const original = window.sessionStorage.getItem.bind(window.sessionStorage)
    window.sessionStorage.getItem = () => {
      throw new Error('bloque')
    }

    expect(getToken()).toBeNull()
    expect(() => setToken('x')).not.toThrow()

    window.sessionStorage.getItem = original
  })
})
