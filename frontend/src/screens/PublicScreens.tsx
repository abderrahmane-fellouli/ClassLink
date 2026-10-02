import { useEffect, useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useI18n } from '../i18n'
import { auth as authApi } from '../lib/endpoints'
import { errorMessage } from '../lib/api'
import { Btn, Icons, Input, Alert } from '../components/UI'

const AUTH_DOMAINS = ['ofppt-edu.ma', 'ofppt.ma']

/* ══ Marque + pied de page publics ══════════════════════════════════ */

function PublicNav({ right }: { right?: ReactNode }) {
  const { t } = useI18n()
  return (
    <nav className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-[var(--border)]">
      <div className="max-w-6xl mx-auto px-5 md:px-8 h-16 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-2.5">
          <Icons.Logo/>
          <span className="font-display text-lg font-semibold">{t('common.appName')}</span>
        </Link>
        <div className="flex items-center gap-3">
          <span className="hidden sm:block text-xs text-[var(--muted-foreground)]">v1.0 · OFPPT</span>
          {right ?? <Btn onClick={() => { window.location.href = '/login' }}>{t('home.login')}</Btn>}
        </div>
      </div>
    </nav>
  )
}

function PublicFooter() {
  const { t } = useI18n()
  return (
    <footer className="border-t border-[var(--border)] bg-white">
      <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Icons.Logo/>
          <span className="font-display font-semibold">{t('common.appName')}</span>
          <span className="text-xs text-[var(--muted-foreground)]">v1.0</span>
        </div>
        <div className="flex items-center gap-4 text-xs text-[var(--muted-foreground)]">
          <Link to="/privacy" className="hover:text-[var(--foreground)]">{t('home.privacy')}</Link>
          <span>{t('home.rights')}</span>
        </div>
      </div>
    </footer>
  )
}

/* ══ Accueil ════════════════════════════════════════════════════════ */

export function HomeScreen() {
  const { t } = useI18n()

  const features = [
    { icon: <Icons.Class/>, title: t('home.feature.classes.title'), desc: t('home.feature.classes.desc') },
    { icon: <Icons.Quiz/>, title: t('home.feature.quiz.title'), desc: t('home.feature.quiz.desc') },
    { icon: <Icons.Chart/>, title: t('home.feature.progress.title'), desc: t('home.feature.progress.desc') },
    { icon: <Icons.Heart/>, title: t('home.feature.partners.title'), desc: t('home.feature.partners.desc') },
    { icon: <Icons.Shield/>, title: t('home.feature.auth.title'), desc: t('home.feature.auth.desc') },
    { icon: <Icons.Upload/>, title: t('home.feature.ai.title'), desc: t('home.feature.ai.desc') },
  ]

  return (
    <div className="min-h-dvh" style={{ background: 'var(--background)' }}>
      <PublicNav/>

      <section className="max-w-6xl mx-auto px-5 md:px-8 pt-16 pb-20 grid md:grid-cols-2 gap-12 items-center">
        <div>
          <div
            className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium mb-6"
            style={{ background: 'rgba(232,130,12,0.1)', color: 'var(--accent)' }}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-[var(--accent)]"/>
            {t('common.tagline')}
          </div>
          <h1 className="font-display text-4xl md:text-5xl font-semibold leading-tight mb-5">
            {t('home.hero.line1')}
            <br/>
            {t('home.hero.line2')}
            <br/>
            <span style={{ color: 'var(--accent)' }}>{t('home.hero.accent')}</span>
          </h1>
          <p className="text-[var(--muted-foreground)] leading-relaxed mb-8 max-w-md">{t('home.hero.body')}</p>
          <div className="flex flex-col sm:flex-row gap-3">
            <Btn size="lg" onClick={() => { window.location.href = '/login' }}>{t('home.cta')}</Btn>
            <Btn size="lg" variant="secondary" onClick={() => { window.location.href = '/privacy' }}>{t('home.learnMore')}</Btn>
          </div>
          <p className="text-xs text-[var(--muted-foreground)] mt-4">
            {t('home.restricted')}{' '}
            {AUTH_DOMAINS.map(domain => (
              <code key={domain} className="font-mono bg-[var(--muted)] px-1 rounded">{domain}</code>
            ))}
          </p>
        </div>

        <div className="hidden md:block">
          <div className="relative">
            <div className="rounded-2xl overflow-hidden shadow-2xl" style={{ background: 'var(--sidebar)' }}>
              <div className="p-4 border-b border-white/10 flex items-center gap-3">
                <Icons.Logo/>
                <span className="text-white font-display font-semibold">{t('common.appName')}</span>
              </div>
              <div className="p-4 space-y-2">
                {[t('home.demoClasses.0'), t('home.demoClasses.1'), t('home.demoClasses.2')].map(name => (
                  <div
                    key={name}
                    className="flex items-center gap-3 px-3 py-2.5 rounded-lg"
                    style={{ background: 'rgba(255,255,255,0.08)' }}
                  >
                    <Icons.Class/>
                    <p className="text-white/80 text-xs">{name}</p>
                  </div>
                ))}
                <div
                  className="flex items-center gap-3 px-3 py-2.5 rounded-lg mt-3"
                  style={{ background: 'rgba(232,130,12,0.15)' }}
                >
                  <Icons.Bell/>
                  <p className="text-xs" style={{ color: '#FDBA74' }}>{t('home.pendingRequests')}</p>
                </div>
              </div>
            </div>
            <div className="absolute -bottom-5 -right-5 bg-white rounded-xl shadow-xl p-4 w-40 border border-[var(--border)]">
              <p className="text-xs font-medium mb-2">{t('home.globalProgress')}</p>
              <p className="text-2xl font-display font-semibold text-[var(--primary)]">78%</p>
              <div className="mt-2 h-1.5 rounded-full bg-[var(--muted)]">
                <div className="h-full rounded-full bg-[var(--primary)]" style={{ width: '78%' }}/>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="bg-white border-y border-[var(--border)] py-16">
        <div className="max-w-6xl mx-auto px-5 md:px-8">
          <h2 className="font-display text-3xl font-semibold text-center mb-2">{t('home.features.title')}</h2>
          <p className="text-center text-[var(--muted-foreground)] text-sm mb-12">{t('home.features.subtitle')}</p>
          <div className="grid sm:grid-cols-2 md:grid-cols-3 gap-6">
            {features.map(f => (
              <div
                key={f.title}
                className="p-6 rounded-[var(--radius)] border border-[var(--border)] hover:border-[var(--primary)]/30 hover:shadow-md transition-all group"
              >
                <div
                  className="w-10 h-10 rounded-lg flex items-center justify-center mb-4 group-hover:bg-[var(--primary)] group-hover:text-white transition-all"
                  style={{ background: 'var(--secondary)', color: 'var(--primary)' }}
                >
                  {f.icon}
                </div>
                <h3 className="font-semibold mb-2">{f.title}</h3>
                <p className="text-sm text-[var(--muted-foreground)] leading-relaxed">{f.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="py-16" style={{ background: 'var(--sidebar)' }}>
        <div className="max-w-2xl mx-auto px-5 text-center">
          <h2 className="font-display text-3xl font-semibold text-white mb-4">{t('home.ctaTitle')}</h2>
          <p className="text-sm mb-8" style={{ color: 'rgba(255,255,255,0.55)' }}>{t('home.ctaBody')}</p>
          <Btn size="lg" variant="accent" onClick={() => { window.location.href = '/login' }}>
            {t('home.ctaButton')}
          </Btn>
        </div>
      </section>

      <PublicFooter/>
    </div>
  )
}

/* ══ Confidentialité ════════════════════════════════════════════════ */

export function PrivacyScreen() {
  const { t } = useI18n()
  const sections = [
    { title: t('privacy.s1.title'), body: t('privacy.s1.body') },
    { title: t('privacy.s2.title'), body: t('privacy.s2.body') },
    { title: t('privacy.s3.title'), body: t('privacy.s3.body') },
    { title: t('privacy.s4.title'), body: t('privacy.s4.body') },
    { title: t('privacy.s5.title'), body: t('privacy.s5.body') },
    { title: t('privacy.s6.title'), body: t('privacy.s6.body') },
    { title: t('privacy.s7.title'), body: t('privacy.s7.body') },
  ]

  return (
    <div className="min-h-dvh" style={{ background: 'var(--background)' }}>
      <PublicNav/>

      <div className="max-w-3xl mx-auto px-5 md:px-8 py-12">
        <div className="mb-10">
          <p className="text-xs font-medium text-[var(--muted-foreground)] uppercase tracking-wider mb-2">{t('privacy.eyebrow')}</p>
          <h1 className="font-display text-4xl font-semibold mb-3">{t('privacy.title')}</h1>
          <p className="text-sm text-[var(--muted-foreground)]">{t('privacy.updated')}</p>
        </div>

        <div className="bg-[var(--secondary)] border border-[var(--border)] rounded-[var(--radius)] p-4 mb-8">
          <p className="text-sm">
            <strong>{t('privacy.summaryTitle')} :</strong> {t('privacy.summary')}
          </p>
        </div>

        <div className="space-y-8">
          {sections.map(s => (
            <div key={s.title}>
              <h2 className="font-display text-lg font-semibold mb-2">{s.title}</h2>
              <p className="text-sm text-[var(--muted-foreground)] leading-7">{s.body}</p>
            </div>
          ))}
        </div>

        <div className="border-t border-[var(--border)] mt-12 pt-8 flex flex-col sm:flex-row items-center justify-between gap-4">
          <p className="text-xs text-[var(--muted-foreground)]">{t('home.rights')}</p>
          <div className="flex gap-3">
            <Link to="/"><Btn variant="secondary">{t('nav.dashboard')}</Btn></Link>
            <Link to="/login"><Btn>{t('home.login')}</Btn></Link>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ══ Connexion ═══════════════════════════════════════════════════════ */

export function LoginScreen() {
  const { t, locale } = useI18n()
  const navigate = useNavigate()
  const { signInWithOtp, signInWithDevRole, microsoftRedirectUrl } = useAuth()

  const [mode, setMode] = useState<'main' | 'email' | 'code'>('main')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function sendOtp() {
    setPending(true)
    setError(null)
    try {
      const response = await authApi.requestOtp(email, { locale })
      setNotice(response.message || t('login.otpEmail.sent'))
      setMode('code')
    } catch (cause) {
      setError(errorMessage(cause, t('common.networkError')))
    } finally {
      setPending(false)
    }
  }

  async function verifyOtp() {
    setPending(true)
    setError(null)
    try {
      await signInWithOtp(email, code)
      navigate('/app', { replace: true })
    } catch (cause) {
      setError(errorMessage(cause, t('error.unknown')))
    } finally {
      setPending(false)
    }
  }

  async function devLogin(role: 'student' | 'teacher' | 'admin') {
    setPending(true)
    setError(null)
    try {
      await signInWithDevRole(role)
      navigate('/app', { replace: true })
    } catch (cause) {
      setError(errorMessage(cause, t('common.networkError')))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="min-h-dvh flex">
      <div className="hidden lg:flex flex-col justify-between w-1/2 p-12 relative overflow-hidden" style={{ background: 'var(--sidebar)' }}>
        <div className="absolute inset-0 pointer-events-none">
          <div
            className="absolute -top-24 -left-24 w-96 h-96 rounded-full opacity-10"
            style={{ background: 'radial-gradient(circle, var(--accent), transparent)' }}
          />
        </div>
        <div className="flex items-center gap-3 relative z-10">
          <Icons.Logo/>
          <span className="font-display text-xl font-semibold text-white">{t('common.appName')}</span>
        </div>
        <div className="relative z-10">
          <p className="text-xs font-medium uppercase tracking-widest mb-4" style={{ color: 'rgba(232,130,12,0.9)' }}>
            {t('login.brandTagline')}
          </p>
          <h1 className="font-display text-4xl font-semibold text-white leading-tight mb-5">
            {t('login.brandTitle1')}
            <br/>
            {t('login.brandTitle2')}
            <br/>
            {t('login.brandTitle3')}
          </h1>
          <p style={{ color: 'rgba(255,255,255,0.5)', lineHeight: '1.7' }} className="text-sm max-w-xs">{t('login.brandBody')}</p>
          <div className="mt-8 flex flex-wrap gap-4">
            {[t('login.brand1'), t('login.brand2'), t('login.brand3')].map(label => (
              <div key={label} className="flex items-center gap-2">
                <div className="w-1.5 h-1.5 rounded-full" style={{ background: 'var(--accent)' }}/>
                <span className="text-xs" style={{ color: 'rgba(255,255,255,0.55)' }}>{label}</span>
              </div>
            ))}
          </div>
        </div>
        <p className="text-xs relative z-10" style={{ color: 'rgba(255,255,255,0.25)' }}>{t('home.rights')}</p>
      </div>

      <div className="flex-1 flex flex-col items-center justify-center px-6 py-12" style={{ background: 'var(--background)' }}>
        <div className="lg:hidden flex items-center gap-2 mb-8">
          <Icons.Logo/>
          <span className="font-display text-xl font-semibold">{t('common.appName')}</span>
        </div>

        <div className="w-full max-w-sm">
          {error && <div className="mb-4"><Alert message={error} type="error"/></div>}

          {mode === 'main' && (
            <>
              <h2 className="font-display text-2xl font-semibold mb-1">{t('login.title')}</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-8">{t('login.subtitle')}</p>

              <a
                href={microsoftRedirectUrl()}
                className="w-full flex items-center justify-center gap-3 px-5 py-3 rounded-[var(--radius)] border border-[var(--border)] bg-white hover:bg-[var(--muted)] font-medium text-sm transition-all mb-4"
              >
                <Icons.Microsoft/>
                {t('login.microsoft')}
              </a>

              <div className="flex items-center gap-3 my-5">
                <div className="flex-1 h-px bg-[var(--border)]"/>
                <span className="text-xs text-[var(--muted-foreground)]">{t('login.or')}</span>
                <div className="flex-1 h-px bg-[var(--border)]"/>
              </div>

              <button onClick={() => setMode('email')} className="w-full text-sm text-[var(--primary)] font-medium hover:underline">
                {t('login.otpLink')}
              </button>

              {import.meta.env.DEV && (
                <div className="mt-8 p-3 rounded-lg border border-dashed border-[var(--border)]">
                  <p className="text-xs text-[var(--muted-foreground)] mb-2 font-medium">{t('home.demoTitle')}</p>
                  <div className="flex gap-2">
                    {(['student', 'teacher', 'admin'] as const).map(role => (
                      <button
                        key={role}
                        disabled={pending}
                        onClick={() => void devLogin(role)}
                        className="flex-1 text-xs py-1.5 rounded bg-[var(--secondary)] text-[var(--primary)] font-medium hover:bg-[var(--muted)] disabled:opacity-50"
                      >
                        {t(role === 'student' ? 'role.student' : role === 'teacher' ? 'role.teacher' : 'role.admin')}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </>
          )}

          {mode === 'email' && (
            <>
              <button onClick={() => setMode('main')} className="text-xs text-[var(--muted-foreground)] mb-6 hover:text-[var(--foreground)]">
                ← {t('common.back')}
              </button>
              <h2 className="font-display text-2xl font-semibold mb-1">{t('login.otpEmail.title')}</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-8">{t('login.otpEmail.subtitle')}</p>
              <div className="space-y-4">
                <Input
                  label={t('login.otpEmail.label')}
                  type="email"
                  placeholder={t('login.otpEmail.placeholder')}
                  value={email}
                  onChange={setEmail}
                  autoComplete="email"
                />
                <Btn full onClick={() => void sendOtp()} disabled={pending || !email.includes('@')}>
                  {pending ? t('login.otpEmail.sending') : t('login.otpEmail.send')}
                </Btn>
              </div>
            </>
          )}

          {mode === 'code' && (
            <>
              <button onClick={() => setMode('email')} className="text-xs text-[var(--muted-foreground)] mb-6 hover:text-[var(--foreground)]">
                ← {t('common.back')}
              </button>
              <h2 className="font-display text-2xl font-semibold mb-1">{t('login.otpCode.title')}</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-6">{t('login.otpCode.subtitle')}</p>

              {notice && <div className="mb-4"><Alert message={notice} type="info"/></div>}

              <div className="space-y-4">
                <Input
                  label={t('login.otpCode.label')}
                  placeholder="123456"
                  value={code}
                  onChange={value => setCode(value.replace(/\D/g, '').slice(0, 6))}
                  inputMode="numeric"
                />
                <Btn full onClick={() => void verifyOtp()} disabled={pending || code.length !== 6}>
                  {pending ? t('common.loading') : t('login.otpCode.verify')}
                </Btn>
                <button onClick={() => void sendOtp()} className="text-xs text-[var(--muted-foreground)] hover:underline w-full text-center">
                  {t('login.otpCode.resend')}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

/* ══ Rôle en attente de validation ═════════════════════════════════ */

export function PendingScreen() {
  const { t } = useI18n()
  const { signOut } = useAuth()
  const navigate = useNavigate()

  return (
    <div className="min-h-dvh flex flex-col items-center justify-center px-5 text-center" style={{ background: 'var(--background)' }}>
      <div className="mb-6"><Icons.Logo/></div>
      <div className="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6" style={{ background: 'rgba(232,130,12,0.12)', color: 'var(--accent)' }}>
        <Icons.Clock/>
      </div>
      <h1 className="font-display text-3xl font-semibold mb-3">{t('role.pending')}</h1>
      <p className="text-[var(--muted-foreground)] max-w-sm mb-8 leading-relaxed">{t('denied.step2')}</p>
      <Btn variant="secondary" onClick={() => void signOut().then(() => navigate('/login', { replace: true }))}>
        {t('nav.logout')}
      </Btn>
    </div>
  )
}

/* ══ Reprise après le callback Microsoft ════════════════════════════ */

/** §17.6 : le serveur renvoie l'application avec `#token=...` dans le fragment. */
export function MicrosoftCallbackScreen() {
  const { t } = useI18n()
  const { adoptCallbackToken } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    void (async () => {
      const match = window.location.hash.match(/token=([^&]+)/)
      if (!match) {
        navigate('/login', { replace: true })
        return
      }

      try {
        const user = await adoptCallbackToken(decodeURIComponent(match[1]))
        if (user) {
          navigate('/app', { replace: true })
          return
        }
        setError(t('error.unknown'))
      } catch (cause) {
        setError(errorMessage(cause, t('error.unknown')))
      } finally {
        // Le fragment est purgé : le jeton ne reste jamais dans l'historique.
        window.history.replaceState(null, '', window.location.pathname)
        controller.abort()
      }
    })()

    return () => controller.abort()
  }, [adoptCallbackToken, navigate, t])

  return (
    <div className="min-h-dvh flex items-center justify-center" style={{ background: 'var(--background)' }}>
      {error ? (
        <div className="max-w-sm w-full px-5">
          <Alert message={error} type="error"/>
        </div>
      ) : (
        <p className="text-sm text-[var(--muted-foreground)]">{t('common.loading')}</p>
      )}
    </div>
  )
}
