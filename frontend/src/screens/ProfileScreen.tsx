import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useI18n } from '../i18n'
import { errorMessage } from '../lib/api'
import { notifications, profile } from '../lib/endpoints'
import { useAction, useAsync } from '../lib/useAsync'
import { describeNotification } from '../components/AppShell'
import type { Locale } from '../lib/types'
import { Alert, AsyncBoundary, Avatar, Btn, ConfirmButton, Card, EmptyState, Input, PageHeader, Tabs, Toggle } from '../components/UI'

export function ProfileScreen() {
  const navigate = useNavigate()
  const { user, updateProfile, signOut } = useAuth()
  const { t, locale, setLocale, formatDate, formatRelative } = useI18n()
  const [tab, setTab] = useState<'profile' | 'notifications' | 'security'>('profile')
  const [displayName, setDisplayName] = useState(user?.display_name ?? '')
  const [notice, setNotice] = useState<string | null>(null)

  const notifs = useAsync(signal => notifications.list({ signal }), [])
  const saveProfile = useAction()
  const saveLanguage = useAction()
  const revokeSessions = useAction()
  const prefs = useAsync(signal => notifications.preferences({ signal, locale }), [locale])
  const savePrefs = useAction()

  useEffect(() => {
    setDisplayName(user?.display_name ?? '')
  }, [user?.display_name])

  async function save() {
    setNotice(null)
    const result = await saveProfile.run(() => updateProfile({ display_name: displayName }))
    if (result) setNotice(t('profile.saved'))
  }

  async function revoke() {
    await revokeSessions.run(async () => { await profile.destroySessions({ locale }); await signOut(); navigate('/', { replace: true }) })
  }

  return (
    <div className="max-w-2xl">
      <PageHeader title={t('nav.profile')}/>

      <Tabs
        tabs={[
          { id: 'profile', label: t('profile.tab.profile') },
          { id: 'notifications', label: t('profile.tab.notifications') },
          { id: 'security', label: t('profile.tab.security') },
        ]}
        active={tab}
        onChange={value => setTab(value as typeof tab)}
      />

      <div className="mt-5 space-y-4">
        {tab === 'profile' && (
          <>
            <Card className="p-5 flex items-center gap-4">
              <Avatar name={user?.display_name ?? '?'} size="lg" color="var(--accent)" textColor="#fff"/>
              <div className="min-w-0">
                <p className="font-semibold truncate">{user?.display_name}</p>
                <p className="text-xs text-[var(--muted-foreground)] truncate">{user?.email ?? '—'}</p>
                <p className="text-xs text-[var(--muted-foreground)] mt-1">
                  {t(
                    user?.role === 'teacher'
                      ? 'role.teacher'
                      : user?.role === 'admin'
                        ? 'role.admin'
                        : 'role.student',
                  )}
                </p>
              </div>
            </Card>

            <Card className="p-5 space-y-4">
              {notice && <Alert message={notice} type="success"/>}
              {saveProfile.error && <Alert message={errorMessage(saveProfile.error, t('error.unknown'))} type="error"/>}

              <Input label={t('profile.displayName')} value={displayName} onChange={setDisplayName} maxLength={80}/>

              <div>
                {saveLanguage.error && <Alert type="warning" message={t('profile.languageSaveError')}/>}
                <p className="text-sm font-medium mb-2">{t('profile.language')}</p>
                <div className="flex gap-2">
                  {(['fr', 'en'] as Locale[]).map(code => (
                    <button
                      key={code}
                      disabled={saveLanguage.pending}
                      aria-pressed={locale === code}
                      onClick={() => { setLocale(code); void saveLanguage.run(() => updateProfile({ locale: code })) }}
                      className={`px-4 py-2 text-sm rounded-lg border transition-colors ${
                        locale === code
                          ? 'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)] font-medium'
                          : 'border-[var(--border)] text-[var(--muted-foreground)]'
                      }`}
                    >
                      {code === 'fr' ? 'Français' : 'English'}
                    </button>
                  ))}
                </div>
              </div>

              <div className="flex justify-end">
                <Btn onClick={() => void save()} disabled={saveProfile.pending || displayName === user?.display_name}>
                  {saveProfile.pending ? t('common.loading') : t('common.save')}
                </Btn>
              </div>
            </Card>

            <Card className="p-5">
              <p className="text-sm font-medium mb-1">{t('profile.method')}</p>
              <p className="text-xs text-[var(--muted-foreground)] mb-4">
                {t('profile.methodValue')}
                {user?.last_login_at ? ` · ${formatDate(user.last_login_at)}` : ''}
              </p>
              <div className="flex items-center gap-2 text-xs">
                <span className="w-2 h-2 rounded-full bg-green-500"/>
                {t('profile.active')}
              </div>
            </Card>
          </>
        )}

        {tab === 'notifications' && (
          <Card className="p-5">
            <AsyncBoundary loading={prefs.loading} error={prefs.error} onRetry={prefs.reload} errorMessage={t('common.error')}>
              {prefs.data && <div className="space-y-3 mb-5">
                <Toggle label={t('preferences.digest')} checked={prefs.data.email_digest} disabled={savePrefs.pending} onChange={value => void savePrefs.run(async () => { const saved = await notifications.updatePreferences({ ...prefs.data!, email_digest: value }, { locale }); prefs.setData(saved) })}/>
                {(['join_requested', 'membership_accepted', 'membership_rejected', 'membership_removed', 'quiz_published', 'graded', 'partner_request_received', 'partner_request_answered', 'ai_job_finished', 'announcement_published', 'assignment_published'] as const).map(type => <Toggle key={type} label={t(`preferences.${type}`)} checked={prefs.data!.types[type] !== false} disabled={savePrefs.pending} onChange={value => void savePrefs.run(async () => { const saved = await notifications.updatePreferences({ ...prefs.data!, types: { ...prefs.data!.types, [type]: value } }, { locale }); prefs.setData(saved) })}/>)}
              </div>}
            </AsyncBoundary>
            {savePrefs.error && <Alert type="error" message={errorMessage(savePrefs.error, t('error.unknown'))}/>}
            <div className="flex items-center justify-between mb-4">
              <p className="text-sm font-medium">{t('profile.prefs')}</p>
              <Btn
                size="sm"
                variant="ghost"
                onClick={() => void notifications.markAllRead({ locale }).then(notifs.reload)}
              >
                {t('profile.markAllRead')}
              </Btn>
            </div>

            <AsyncBoundary loading={notifs.loading} error={notifs.error} onRetry={notifs.reload} errorMessage={t('common.error')} isEmpty={!notifs.data?.data.length} empty={<EmptyState message={t('profile.noNotifications')}/>}>
              <div className="divide-y divide-[var(--border)] -mx-2">
                {(notifs.data?.data ?? []).map(item => (
                  <button
                    key={item.id}
                    onClick={() => void notifications.markRead(item.id, { locale }).then(notifs.reload)}
                    className={`w-full text-left px-2 py-3 ${item.is_read ? '' : 'bg-[var(--secondary)]/60 rounded-lg'}`}
                  >
                    <p className="text-sm">{describeNotification(item, t)}</p>
                    <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{formatRelative(item.created_at)}</p>
                  </button>
                ))}
              </div>
            </AsyncBoundary>
          </Card>
        )}

        {tab === 'security' && (
          <Card className="p-5">
            <div className="mb-5"><ConfirmButton variant="secondary" onClick={() => { navigate('/', { replace: true }); void signOut() }}>{t('nav.logout')}</ConfirmButton></div>
            <p className="text-sm font-medium mb-1">{t('profile.sessions')}</p>
            <p className="text-xs text-[var(--muted-foreground)] mb-4">{t('profile.sessionsBody')}</p>
            {revokeSessions.error && (
              <div className="mb-3">
                <Alert message={errorMessage(revokeSessions.error, t('error.unknown'))} type="error"/>
              </div>
            )}
            <ConfirmButton variant="danger" onClick={() => void revoke()} disabled={revokeSessions.pending}>
              {t('profile.logoutAll')}
            </ConfirmButton>
          </Card>
        )}
      </div>
    </div>
  )
}
