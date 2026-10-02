import '@testing-library/jest-dom/vitest'
import { afterEach, beforeEach, vi } from 'vitest'
import { cleanup } from '@testing-library/react'
import { clearToken } from '../src/lib/session'

/**
 * Chaque test repart d'un stockage vide : un jeton laissé par un test
 * précédent donnerait un faux positif sur les en-têtes `Authorization`.
 */
beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  clearToken()
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

/** Réponse JSON minimale, avec les en-têtes attendus par `src/lib/api.ts`. */
export function jsonResponse(body: unknown, init: { status?: number } = {}): Response {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { 'content-type': 'application/json' },
  })
}
