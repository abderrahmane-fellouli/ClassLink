import { describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  SessionExpiredError,
  api,
  errorMessage,
  firstFieldError,
  request,
  setUnauthorizedHandler,
} from '../src/lib/api'
import { setToken } from '../src/lib/session'
import { jsonResponse } from './setup'

/** Dernier appel `fetch` : permet d'inspecter URL, method et en-tetes. */
function lastCall(mock: ReturnType<typeof vi.fn>): [string, RequestInit] {
  return mock.mock.calls.at(-1) as [string, RequestInit]
}

function headersOf(call: [string, RequestInit]): Headers {
  return new Headers(call[1].headers as HeadersInit)
}

describe('api client', () => {
  it('attaches the bearer token and the locale', async () => {
    setToken('jeton-abc')
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/me', { locale: 'fr' })

    const headers = headersOf(lastCall(fetchMock))
    expect(headers.get('Authorization')).toBe('Bearer jeton-abc')
    expect(headers.get('Accept-Language')).toBe('fr')
    expect(headers.get('Accept')).toBe('application/json')
  })

  it('sends no Authorization header on an anonymous request', async () => {
    setToken('jeton-abc')
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/otp/send', { method: 'POST', body: { email: 'a@b.ma' }, anonymous: true })

    expect(headersOf(lastCall(fetchMock)).get('Authorization')).toBeNull()
  })

  it('sends JSON for a body and FormData untouched for an upload', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/classes/1/announcements', { method: 'POST', body: { title: 'Hello' } })
    expect(lastCall(fetchMock)[1].body).toBe('{"title":"Hello"}')
    expect(headersOf(lastCall(fetchMock)).get('Content-Type')).toBe('application/json')

    const form = new FormData()
    form.append('file', new Blob(['x']), 'rendu.pdf')
    await request('/assignments/1/submissions', { method: 'POST', formData: form })
    // Multipart : le navigateur doit poser lui-meme la frontiere.
    expect(lastCall(fetchMock)[1].body).toBe(form)
    expect(headersOf(lastCall(fetchMock)).get('Content-Type')).toBeNull()
  })

  it('normalises an error payload into ApiError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(
          {
            message: 'Code de classe invalide.',
            code: 'invalid_code',
            errors: { code: ['Code de classe invalide.'] },
            context: { retry_after: 86400 },
          },
          { status: 422 },
        ),
      ),
    )

    const error = await request('/join-requests', { method: 'POST', body: {} }).catch(e => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).code).toBe('invalid_code')
    expect((error as ApiError).errors.code).toEqual(['Code de classe invalide.'])
    expect((error as ApiError).context).toEqual({ retry_after: 86400 })
  })

  it('clears the token and notifies on 401', async () => {
    setToken('jeton-expire')
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({}, { status: 401 })))

    const error = await request('/me').catch(e => e)

    expect(error).toBeInstanceOf(SessionExpiredError)
    expect(onUnauthorized).toHaveBeenCalledOnce()
    setUnauthorizedHandler(null)
  })

  it('downloads a private file with the token instead of a bare link', async () => {
    setToken('jeton-abc')
    const fetchMock = vi.fn().mockResolvedValue(
      new Response('contenu', { status: 200, headers: { 'content-type': 'application/pdf' } }),
    )
    vi.stubGlobal('fetch', fetchMock)
    const createObjectURL = vi.fn().mockReturnValue('blob:fake')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    await api.download('/materials/7/download', 'cours.pdf')

    expect(headersOf(lastCall(fetchMock)).get('Authorization')).toBe('Bearer jeton-abc')
    expect(click).toHaveBeenCalledOnce()
    // Le jeton ne doit jamais apparaitre dans l'URL.
    expect(lastCall(fetchMock)[0]).not.toContain('jeton-abc')
  })

  it('opens the signed URL when the API answers with JSON', async () => {
    const open = vi.fn()
    vi.stubGlobal('open', open)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ url: 'https://s3.test/signed' })))

    await api.download('/materials/7/download', 'cours.pdf')

    expect(open).toHaveBeenCalledWith('https://s3.test/signed', '_blank', 'noopener')
  })
})

describe('errorMessage', () => {
  it('prefers the message already translated by the API', () => {
    expect(errorMessage(new ApiError(422, 'Code invalide.'), 'repli')).toBe('Code invalide.')
  })

  it('falls back for an expired session instead of leaking the raw code', () => {
    expect(errorMessage(new SessionExpiredError(), 'repli')).toBe('repli')
  })

  it('falls back on a non-error value', () => {
    expect(errorMessage(null, 'repli')).toBe('repli')
    expect(errorMessage('texte', 'repli')).toBe('repli')
  })
})

describe('firstFieldError', () => {
  it('returns the first validation message for the field', () => {
    const error = new ApiError(422, 'x', { errors: { code: ['Code invalide.', 'Code deja utilise.'] } })

    expect(firstFieldError(error, 'code', 'repli')).toBe('Code invalide.')
  })

  it('falls back when the field is absent', () => {
    expect(firstFieldError(new ApiError(422, 'x'), 'code', 'repli')).toBe('repli')
    expect(firstFieldError(null, 'code', 'repli')).toBe('repli')
  })
})
