import { useEffect, useState, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useI18n, type Translate } from '../i18n'
import { notifications as notificationsApi } from '../lib/endpoints'
import { useAsync } from '../lib/useAsync'
import type { Role } from '../lib/types'
import { ConfirmButton, Dialog, Icons, LocaleSwitch } from './UI'

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
  const { user, role, signOut, updateProfile } = useAuth()
  const { t, locale, formatRelative } = useI18n()
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

  const sidebarContent = (
    <div className="flex flex-col h-full" style={{ background: 'var(--sidebar)' }}>
      <div className="flex items-center gap-3 px-5 py-5 border-b border-white/10">
        <Icons.Logo/>
        <span className="font-display text-lg font-semibold tracking-tight text-white">{t('common.appName')}</span>
      </div>

      <div className="px-5 pt-4 pb-1">
        <span
          className="text-xs font-medium px-2 py-1 rounded-md"
          style={{ background: 'rgba(111,168,220,0.2)', color: 'var(--secondary)' }}
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
            onClick={() => setMobileOpen(false)}
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
        <LocaleSwitch locale={locale} onChange={value => { void updateProfile({ locale: value }).catch(() => {}) }}/>
        <ConfirmButton variant="ghost"
          onClick={() => {
            setPanelOpen(false)
            void signOut().then(() => navigate('/login', { replace: true }))
          }}
          className="text-white/45 hover:text-white transition-colors"
          title={t('nav.logout')}
          aria-label={t('nav.logout')}
        >
          <Icons.Logout/>
        </ConfirmButton>
      </div>
    </div>
  )

  return (
    <div className="flex min-h-dvh">
      <aside
        className="hidden md:flex flex-col"
        style={{ width: 240, minHeight: '100vh', position: 'sticky', top: 0, background: 'var(--sidebar)' }}
      >
        {sidebarContent}
      </aside>

      {mobileOpen && (
        <Dialog title={t('nav.dashboard')} onClose={() => setMobileOpen(false)}>
          <aside className="relative w-64 flex flex-col z-10">
            {sidebarContent}
          </aside>
        </Dialog>
      )}

      <div className="flex-1 flex flex-col min-w-0">
        <header className="md:hidden flex items-center justify-between px-4 py-3 border-b border-[var(--border)] bg-white sticky top-0 z-40">
          <button onClick={() => setMobileOpen(true)} className="p-2 rounded-lg hover:bg-[var(--muted)]" aria-label={t('nav.dashboard')}>
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

        <main className={`flex-1 px-4 sm:px-6 lg:px-8 py-6 lg:py-8 max-w-6xl w-full mx-auto ${role === 'student' ? 'pb-24 md:pb-8' : ''}`}>
          <div className="hidden md:flex justify-end mb-4"><button aria-label={t('nav.notifications')} onClick={() => setPanelOpen(true)} className="flex items-center gap-2"><Icons.Bell/>{unread > 0 && <span>{unread}</span>}</button></div>
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
        {role === 'student' && <nav aria-label={t('nav.dashboard')} className="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-[var(--border)] flex justify-around pb-[env(safe-area-inset-bottom)]">
          {nav.filter(item => item.labelKey !== 'nav.join').map(item => <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `flex flex-col items-center justify-center gap-1 px-1 py-2 text-[10px] ${isActive ? 'text-[var(--primary)] font-semibold' : 'text-[var(--muted-foreground)]'}`}>
            {item.icon}{t(item.labelKey)}
          </NavLink>)}
        </nav>}
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
    <Dialog title={t('nav.notifications')} onClose={onClose}>
      <div className="flex flex-col">
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
    </Dialog>
  )
}

type NotificationLike = import('../lib/types').ApiNotification

/**
 * Traduit une notification à partir de son `type` et de son `payload`.
 *
 * Les `type` et les clés de `payload` correspondent aux constantes de
 * `backend/app/Services/NotificationService.php` (`join_requested`,
 * `quiz_published`, `announcement_published`…). Le backend n'a jamais émis
 * de type à points : toute notification retombait donc sur `notif.unknown`.
 */
export function describeNotification(item: NotificationLike, t: Translate): string {
  const payload = (item.payload ?? {}) as Record<string, string | number | null | undefined>
  const field = (key: string): string => (payload[key] == null ? '' : String(payload[key]))

  switch (item.type) {
    case 'join_requested':
      return t('notif.join.requested', { name: field('student_name'), class: field('classroom_name') })
    case 'membership_accepted':
      return t('notif.join.accepted', { class: field('classroom_name') })
    case 'membership_rejected':
      return t('notif.join.rejected', { class: field('classroom_name') })
    case 'membership_removed':
      return t('notif.membership.removed', { class: field('classroom_name') })
    case 'partner_request_received':
      return t('notif.partner.received', { name: field('from_name'), class: field('classroom_name') })
    case 'partner_request_answered':
      return t('notif.partner.answered', { status: t(field('status') === 'accepted' ? 'join.status.accepted' : field('status') === 'rejected' ? 'join.status.rejected' : 'join.status.pending') })
    case 'quiz_published':
      return t('notif.quiz.published', { title: field('title'), class: field('classroom_name') })
    case 'announcement_published':
      return t('notif.announcement.published', { title: field('title'), class: field('classroom_name') })
    case 'assignment_published':
      return t('notif.assignment.published', { title: field('title'), class: field('classroom_name') })
    case 'graded':
      return t('notif.assignment.graded', { grade: field('grade') })
    case 'ai_job_finished':
      return t('notif.ai.finished', { status: t(['done', 'succeeded'].includes(field('status')) ? 'status.done' : field('status') === 'failed' ? 'status.failed' : 'status.processing') })
    default:
      return t('notif.unknown')
  }
}
