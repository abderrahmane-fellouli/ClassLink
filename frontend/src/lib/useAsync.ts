import { useCallback, useEffect, useRef, useState } from 'react'
import { SessionExpiredError } from './api'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  /** Toujours une `Error` : le rendu n'a jamais à tester un `unknown`. */
  error: Error | null
  reload: () => void
  setData: (updater: T | ((previous: T | null) => T | null)) => void
}

/** Normalise une rejection quelconque en `Error`. */
export function toError(cause: unknown): Error {
  if (cause instanceof Error) return cause
  return new Error(typeof cause === 'string' ? cause : '')
}

/**
 * Chargement d'une ressource : annule la requête précédente, ignore les
 * réponses obsolètes et distingue « chargement » de « erreur » de « vide ».
 */
export function useAsync<T>(loader: (signal: AbortSignal) => Promise<T>, deps: unknown[]): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<Error | null>(null)
  const [nonce, setNonce] = useState(0)

  const loaderRef = useRef(loader)
  loaderRef.current = loader

  useEffect(() => {
    const controller = new AbortController()
    let active = true

    setLoading(true)
    setError(null)

    loaderRef
      .current(controller.signal)
      .then(result => {
        if (active) setData(result)
      })
      .catch((cause: unknown) => {
        // Une requête annulée n'est pas une erreur d'affichage.
        if (!active || controller.signal.aborted) return
        if (cause instanceof SessionExpiredError) return
        setError(toError(cause))
      })
      .finally(() => {
        if (active && !controller.signal.aborted) setLoading(false)
      })

    return () => {
      active = false
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce])

  const reload = useCallback(() => setNonce(value => value + 1), [])

  const update = useCallback((updater: T | ((previous: T | null) => T | null)) => {
    setData(previous =>
      typeof updater === 'function' ? (updater as (p: T | null) => T | null)(previous) : updater,
    )
  }, [])

  return { data, loading, error, reload, setData: update }
}

/** État « en cours » pour une action (bouton désactivé, spinner). */
export function useAction() {
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<Error | null>(null)
  const running = useRef(false)

  const run = useCallback(async <T,>(task: () => Promise<T>): Promise<T | null> => {
    if (running.current) return null
    running.current = true
    setPending(true)
    setError(null)
    try {
      return await task()
    } catch (cause) {
      setError(toError(cause))
      return null
    } finally {
      running.current = false
      setPending(false)
    }
  }, [])

  return { pending, error, run, clear: () => setError(null) }
}
