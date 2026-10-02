import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Btn, Card, Icons } from './UI'
import type { Role } from '../lib/types'

function FullPageLoader() {
  return (
    <div className="min-h-dvh flex items-center justify-center bg-[var(--background)]">
      <div
        className="w-6 h-6 border-2 border-[var(--primary)] border-t-transparent rounded-full animate-spin"
        role="status"
        aria-label="Chargement"
      />
    </div>
  )
}

/** Redirige vers la connexion en mémorisant la page demandée. */
export function GuestRoute({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <FullPageLoader/>
  if (status === 'authenticated') {
    const from = `${location.pathname}${location.search}`
    return <Navigate to="/app" replace state={{ from }}/>
  }
  return <>{children}</>
}

/** Exige une session ; les rôles `pending` et `denied` sont isolés (§17.6). */
export function ProtectedRoute({ children, roles }: { children: ReactNode; roles?: Role[] }) {
  const { status, role, isDenied, isPending } = useAuth()
  const location = useLocation()

  if (status === 'loading') return <FullPageLoader/>

  if (status === 'anonymous') {
    return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }}/>
  }

  if (isDenied) return <AccessDeniedScreen/>
  if (isPending) return <Navigate to="/pending" replace/>

  if (roles && role && !roles.includes(role)) {
    return <Navigate to="/app" replace/>
  }

  return <>{children}</>
}

export function AccessDeniedScreen() {
  const { user, signOut } = useAuth()
  return (
    <div className="min-h-dvh flex items-center justify-center bg-[var(--background)] p-5">
      <Card className="max-w-md w-full p-8 text-center">
        <div
          className="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4"
          style={{ background: 'var(--danger)', color: '#fff' }}
        >
          <Icons.Shield/>
        </div>
        <h1 className="font-display text-xl font-semibold mb-2">Accès refusé</h1>
        <p className="text-sm text-[var(--muted-foreground)] mb-6">
          {user?.display_name
            ? 'Votre compte n’est rattaché à aucun établissement autorisé. Contactez l’administration ClassLink.'
            : 'Votre compte n’est rattaché à aucun établissement autorisé.'}
        </p>
        <p className="text-xs text-[var(--muted-foreground)] mb-6">
          Un identifiant de démonstration est disponible sur l’écran de connexion en environnement de développement.
        </p>
        <Btn variant="secondary" full onClick={() => void signOut()}>
          Se déconnecter
        </Btn>
      </Card>
    </div>
  )
}
