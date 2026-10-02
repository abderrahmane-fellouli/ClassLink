import { useEffect, useState, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useI18n, type Translate } from '../i18n'
import { notifications as notificationsApi } from '../lib/endpoints'
import { useAsync } from '../lib/useAsync'
import type { Role } from '../lib/types'
import { Icons, LocaleSwitch } from './UI'

type NavEntry = { to: string; labelKey: Parameters<Translate>[0]; icon: ReactNode; end?: boolean }

function navFor(role: Role): NavEntry[] {
  if (role === 'teacher') {
    return [
      { to: '/app', labelKey: 'nav.dashboard', icon: <Icons.Home/>, end: true },
      { to: '/app/classes', labelKey: 'nav.classes', icon: <Icons.Class/> },
      { to: '/app/requests', labelKey: 'nav.requests', icon: <Icons.Bell/> },
      { to: '/app/progression', labelKey: 'nav.progression', icon: <Icons.Chart/> },
      { to: '/app/classes/new', labelKey: 'nav.createClass', icon: <Icons.Plus/> },
      { to: '/app/profile', labelKey: 'nav.profile', icon: <Icons.User/> },
    ]
  }

  if (role === 'admin') {
    return [
      { to: '/app/admin', labelKey: 'nav.adminOverview', icon: <Icons.Chart/>, end: true },
      { to: '/app/admin/users', labelKey: 'nav.adminUsers', icon: <Icons.Users/> },
      { to: '/app/admin/classes', labelKey: 'nav.classes', icon: <Icons.Class/> },
      { to: '/app/admin/ai', labelKey: 'nav.adminAi', icon: <Icons.Cpu/> },
      { to: '/app/admin/audit', labelKey: 'nav.adminAudit', icon: <Icons.Log/> },
      { to: '/app/profile', labelKey: 'nav.profile', icon: <Icons.User/> },
    ]
  }

  return [
    { to: '/app', labelKey: 'nav.dashboard', icon: <Icons.Home/>, end: true },
    { to: '/app/classes', labelKey: 'nav.classes', icon: <Icons.Class/> },
    { to: '/app/deadlines', labelKey: 'nav.deadlines', icon: <Icons.Calendar/> },
    { to: '/app/partners', labelKey: 'nav.partners', icon: <Icons.Heart/> },
    { to: '/app/join', labelKey: 'nav.join', icon: <Icons.Plus/> },
    { to: '/app/profile', labelKey: 'nav.profile', icon: <Icons.User/> },
  ]
}

function roleLabelKey(role: Role | null) {
  if (role === 'teacher') return 'role.teacher' as const
  if (role === 'admin') return 'role.admin' as const
  return 'role.student' as const
}

export function AppShell({ children }: { children: ReactNode }) {
  const { user, role, signOut } = useAuth()
  const { t, locale, setLocale, formatRelative } = useI18n()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [panelOpen, setPanelOpen] = useState(false)

  const notifs = useAsync(
    signal => notificationsApi.list({ locale, signal }),
    [locale],
  )

  const unread = notifs.data?.unread_count ?? 0
  const nav = navFor(role ?? 'student')

  useEffect(() => {
    setMobileOpen(false)
    setPanelOpen(false)
  }, [role])

  const SidebarContent = () => (
    <div className="flex flex-col h-full" style={{ background: 'var(--sidebar)' }}>
      <div className="flex items-center gap-3 px-5 py-5 border-b border-white/10">
        <Icons.Logo/>
        <span className="font-display text-lg font-semibold tracking-tight text-white">{t('common.appName')}</span>
      </div>

      <div className="px-5 pt-4 pb-1">
        <span
          className="text-xs font-medium px-2 py-1 rounded-md"
          style={{ background: 'rgba(232,130,12,0.2)', color: '#FDBA74' }}
        >
          {t(roleLabelKey(role))}
        </span>
      </div>

      <nav className="flex-1 px-3 mt-2 space-y-0.5 overflow-y-auto">
        {nav.map(item => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) =>
              `w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all ${
                isActive
                  ? 'bg-white/12 text-white'
                  : 'text-white/55 hover:bg-white/8 hover:text-white/80'
              }`
            }
          >
            {item.icon}
            <span className="flex-1 text-left">{t(item.labelKey)}</span>
            {item.labelKey === 'nav.requests' && unread > 0 && (
              <span className="w-5 h-5 rounded-full bg-[var(--accent)] text-white text-[10px] flex items-center justify-center">
                {unread}
              </span>
            )}
          </NavLink>
        ))}
      </nav>

      <div className="px-5 py-4 border-t border-white/10 flex items-center gap-3">
        <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold shrink-0" style={{ background: 'var(--accent)', color: 'white' }}>
          {user?.initials ?? '—'}
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-xs font-medium text-white truncate">{user?.display_name ?? ''}</p>
          <p className="text-xs text-white/45 truncate">{user?.email ?? t('common.tagline')}</p>
        </div>
        <LocaleSwitch locale={locale} onChange={setLocale}/>
        <button
          onClick={() => {
            setPanelOpen(false)
            void signOut().then(() => navigate('/login', { replace: true }))
          }}
          className="text-white/45 hover:text-white transition-colors"
          title={t('nav.logout')}
          aria-label={t('nav.logout')}
        >
          <Icons.Logout/>
        </button>
      </div>
    </div>
  )

  return (
    <div className="flex min-h-dvh">
      <aside
        className="hidden md:flex flex-col"
        style={{ width: 240, minHeight: '100vh', position: 'sticky', top: 0, background: 'var(--sidebar)' }}
      >
        <SidebarContent/>
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden">
          <div className="absolute inset-0 bg-black/60" onClick={() => setMobileOpen(false)}/>
          <aside className="relative w-64 flex flex-col z-10">
            <SidebarContent/>
          </aside>
        </div>
      )}

      <div className="flex-1 flex flex-col min-w-0">
        <header className="md:hidden flex items-center justify-between px-4 py-3 border-b border-[var(--border)] bg-white sticky top-0 z-40">
          <button onClick={() => setMobileOpen(true)} className="p-2 rounded-lg hover:bg-[var(--muted)]" aria-label="Menu">
            <Icons.Menu/>
          </button>
          <div className="flex items-center gap-2">
            <Icons.Logo/>
            <span className="font-display font-semibold">{t('common.appName')}</span>
          </div>
          <button onClick={() => setPanelOpen(v => !v)} className="p-2 rounded-lg hover:bg-[var(--muted)] relative" aria-label={t('nav.notifications')}>
            <Icons.Bell/>
            {unread > 0 && (
              <span className="absolute top-1 right-1 w-4 h-4 rounded-full bg-[var(--accent)] text-white text-[9px] flex items-center justify-center">
                {unread}
              </span>
            )}
          </button>
        </header>

        <main className="flex-1 px-4 sm:px-6 lg:px-8 py-6 lg:py-8 max-w-6xl w-full mx-auto">
          {panelOpen && (
            <NotificationPanel
              onClose={() => setPanelOpen(false)}
              items={notifs.data?.data ?? []}
              loading={notifs.loading}
              error={notifs.error}
              onReload={notifs.reload}
              onMarkRead={id => {
                void notificationsApi.markRead(id, { locale }).then(notifs.reload)
              }}
              onMarkAllRead={() => {
                void notificationsApi.markAllRead({ locale }).then(notifs.reload)
              }}
              formatRelative={formatRelative}
              onNavigate={path => {
                setPanelOpen(false)
                navigate(path)
              }}
            />
          )}

          {children}
        </main>
      </div>
    </div>
  )
}

function NotificationPanel({
  items,
  loading,
  error,
  onReload,
  onMarkRead,
  onMarkAllRead,
  onClose,
  onNavigate,
  formatRelative,
}: {
  items: import('../lib/types').ApiNotification[]
  loading: boolean
  error: Error | null
  onReload: () => void
  onMarkRead: (id: number) => void
  onMarkAllRead: () => void
  onClose: () => void
  onNavigate: (path: string) => void
  formatRelative: (value: string | null | undefined) => string
}) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-label={t('nav.notifications')}>
      <div className="absolute inset-0 bg-black/40" onClick={onClose}/>
      <div className="relative w-full sm:w-96 h-full bg-white border-l border-[var(--border)] flex flex-col z-10">
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border)]">
          <h2 className="font-display font-semibold">{t('nav.notifications')}</h2>
          <div className="flex items-center gap-1">
            <button
              onClick={onMarkAllRead}
              className="text-xs text-[var(--primary)] hover:underline"
            >
              {t('profile.markAllRead')}
            </button>
            <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--muted)]" aria-label={t('common.close')}>
              <Icons.X/>
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 space-y-2">
          {loading && <p className="text-sm text-[var(--muted-foreground)] py-6 text-center">{t('common.loading')}</p>}
          {error && (
            <div className="text-center py-6">
              <p className="text-sm text-[var(--muted-foreground)] mb-3">{t('common.error')}</p>
              <button onClick={onReload} className="text-sm text-[var(--primary)] hover:underline">
                {t('common.retry')}
              </button>
            </div>
          )}
          {!loading && !error && items.length === 0 && (
            <p className="text-sm text-[var(--muted-foreground)] py-6 text-center">{t('profile.noNotifications')}</p>
          )}

          {items.map(item => (
            <button
              key={item.id}
              onClick={() => {
                if (!item.is_read) onMarkRead(item.id)
                const classroomId = (item.payload?.classroom_id ?? null) as number | null
                if (classroomId) onNavigate(`/app/classes/${classroomId}`)
                else onClose()
              }}
              className={`w-full text-left px-3.5 py-3 rounded-lg border text-sm transition-colors ${
                item.is_read
                  ? 'border-[var(--border)] bg-white'
                  : 'border-[var(--primary)]/25 bg-[var(--secondary)]'
              }`}
            >
              <span className="block font-medium">{describeNotification(item, t)}</span>
              <span className="block text-xs text-[var(--muted-foreground)] mt-1">{formatRelative(item.created_at)}</span>
            </button>
          ))}
        </div>

        <div className="p-3 border-t border-[var(--border)]">
          <button
            onClick={() => {
              setOpen(v => !v)
              onNavigate('/app/profile')
            }}
            className="w-full text-xs text-[var(--muted-foreground)] hover:text-[var(--foreground)]"
          >
            {open ? '' : t('profile.tab.notifications')}
          </button>
        </div>
      </div>
    </div>
  )
}

type NotificationLike = import('../lib/types').ApiNotification

/** Traduit une notification à partir de son `type` et de son `payload`. */
export function describeNotification(item: NotificationLike, t: Translate): string {
  const payload = (item.payload ?? {}) as Record<string, string | number | undefined>

  switch (item.type) {
    case 'join.requested':
      return t('notif.join.requested', { name: String(payload.name ?? ''), class: String(payload.class ?? '') })
    case 'join.accepted':
      return t('notif.join.accepted', { class: String(payload.class ?? '') })
    case 'join.rejected':
      return t('notif.join.rejected', { class: String(payload.class ?? '') })
    case 'quiz.published':
      return t('notif.quiz.published', { title: String(payload.title ?? '') })
    case 'assignment.graded':
      return t('notif.assignment.graded', { title: String(payload.title ?? '') })
    case 'announcement.created':
      return t('notif.announcement.created', {
        name: String(payload.name ?? ''),
        class: String(payload.class ?? ''),
      })
    default:
      return t('notif.unknown')
  }
}
