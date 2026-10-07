import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '../src/context/AuthContext'
import { I18nProvider } from '../src/i18n'
import { GuestRoute, ProtectedRoute } from '../src/components/guards'
import { setToken, setStoredLocale } from '../src/lib/session'
import { jsonResponse } from './setup'
import type { ApiUser } from '../src/lib/types'

/**
 * T-23 / T-24 — Cycle de session côté navigateur.
 *
 * T-23 : après « Se déconnecter », la requête suivante avec l'ancien jeton
 *        renvoie 401 (couverture backend : SessionTest).
 * T-24 : le bouton « Précédent » du navigateur après la déconnexion doit
 *        rediriger vers la connexion, jamais réafficher une page Application.
 *
 * Le second point est un risque réel : `ProtectedRoute` ne teste que l'état
 * React. Si l'état n'est pas remis à `anonymous` à la déconnexion, l'historique
 * du navigateur ré-affiche la page protégée avec un jeton révoqué.
 */

const teacher: ApiUser = {
  id: 1,
  email: 'zakariyae.chergui@ofppt-edu.ma',
  display_name: 'Zakariae Chergui',
  initials: 'ZC',
  role: 'teacher',
  locale: 'fr',
  is_active: true,
  last_login_at: null,
}

function Profile() {
  const { user, signOut, status } = useAuth()
  return (
    <div>
      <p data-testid="status">{status}</p>
      <p data-testid="role">{user?.role ?? 'none'}</p>
      <button type="button" onClick={() => void signOut()}>
        Se déconnecter
      </button>
    </div>
  )
}

/** Reproduit `/app` (protégé) et `/login` (invité) du routeur réel. */
function App() {
  return (
    <I18nProvider>
      <AuthProvider>
        <MemoryRouter initialEntries={['/app/profile']}>
          <Routes>
            <Route
              path="/app/profile"
              element={
                <ProtectedRoute roles={['student', 'teacher', 'admin']}>
                  <Profile />
                </ProtectedRoute>
              }
            />
            <Route
              path="/login"
              element={
                <GuestRoute>
                  <p>Écran de connexion</p>
                </GuestRoute>
              }
            />
            <Route path="/pending" element={<p>Compte en attente de validation</p>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </I18nProvider>
  )
}

describe('T-24 — déconnexion puis bouton « Précédent »', () => {
  beforeEach(() => {
    setStoredLocale('fr')
  })

  it('preserves a token during a temporary outage and retries without granting early access', async () => {
    setToken('synthetic-retry-token')
    const syntheticTeacher = { ...teacher, email: 'synthetic.teacher@ofppt-edu.ma', display_name: 'Synthetic Teacher' }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(jsonResponse({ message: 'Temporary outage' }, { status: 503 })).mockResolvedValue(jsonResponse(syntheticTeacher)))
    render(<App/>)
    expect(await screen.findByText(/serveur est temporairement indisponible/i)).toBeInTheDocument()
    expect(screen.queryByTestId('role')).not.toBeInTheDocument()
    expect(sessionStorage.getItem('classlink.token')).toBe('synthetic-retry-token')
    await userEvent.click(screen.getByRole('button', { name: 'Réessayer' }))
    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('role')).toHaveTextContent('teacher')
  })

  it('redirige vers la connexion au retour arrière, sans réafficher l\'application', async () => {
    // Un jeton valide est present : la page protégée doit d'abord s'afficher.
    setToken('jeton-valide')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(teacher)),
    )

    render(<App/>)

    await waitFor(() => expect(screen.getByTestId('status')).toHaveTextContent('authenticated'))
    expect(screen.getByTestId('role')).toHaveTextContent('teacher')

    // T-23 : la déconnexion appelle l'API puis révoque l'état local.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(null, { status: 204 })),
    )

    await userEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }))

    // T-24 : l'utilisateur est renvoyé vers l'écran de connexion, et la page
    // protégée disparaît de l'arbre (le composant est démonté).
    expect(await screen.findByText('Écran de connexion')).toBeInTheDocument()
    expect(screen.queryByTestId('role')).not.toBeInTheDocument()

    // Aucune requête authentifiée ne doit partir après la déconnexion :
    // le jeton a été purgé de sessionStorage.
    expect(window.sessionStorage.getItem('classlink.token')).toBeNull()
  })

  it('refuse d’afficher la page protégée à un visiteur sans jeton', async () => {
    // T-24 appliqué au cas direct : on arrive sur /app sans session.
    vi.stubGlobal('fetch', vi.fn())

    render(<App/>)

    // `ProtectedRoute` bascule immédiatement : aucun appel réseau n'est fait.
    expect(await screen.findByText('Écran de connexion')).toBeInTheDocument()
    expect(vi.mocked(fetch)).not.toHaveBeenCalled()
  })

  it('isole un compte « en attente » sur son écran dédié', async () => {
    setToken('jeton-pending')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ ...teacher, role: 'pending' }),
      ),
    )

    render(<App/>)

    // §17.6 B : un rôle `pending` n'accède à aucun contenu de classe. Le
    // `ProtectedRoute` le renvoie vers son écran dédié, jamais vers
    // l'application.
    expect(await screen.findByText('Compte en attente de validation')).toBeInTheDocument()
    expect(screen.queryByTestId('role')).not.toBeInTheDocument()
  })

  it('affiche l’écran d’accès refusé à un compte hors domaine', async () => {
    setToken('jeton-refuse')
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ ...teacher, role: 'denied' }),
      ),
    )

    render(<App/>)

    // `AccessDeniedScreen` est rendu directement par `ProtectedRoute`.
    expect(await screen.findByText('Accès refusé')).toBeInTheDocument()
  })
})
