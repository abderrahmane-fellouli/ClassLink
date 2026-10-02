import { useState } from 'react'
import { Btn, Icons } from '../components/UI'
import type { Screen } from '../components/UI'

/* ══════════════════════════════════════════════════════════════
   Part C — Page d'accueil (landing)
══════════════════════════════════════════════════════════════ */
export function HomeScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const features = [
    { icon: <Icons.Class/>, title: 'Classes numériques', desc: 'Créez et gérez vos classes avec un code d\'invitation simple. Ressources, annonces et quiz au même endroit.' },
    { icon: <Icons.Quiz/>, title: 'Quiz interactifs', desc: 'Évaluez les connaissances en temps réel. Générez des quiz automatiquement depuis un PDF grâce à l\'IA.' },
    { icon: <Icons.Chart/>, title: 'Suivi de progression', desc: 'Visualisez la progression de chaque étudiant. Identifiez les besoins et adaptez votre enseignement.' },
    { icon: <Icons.Heart/>, title: 'Partenaires d\'étude', desc: 'Mettez en relation les étudiants pour qu\'ils révisent ensemble selon leurs compétences et disponibilités.' },
    { icon: <Icons.Shield/>, title: 'Authentification sécurisée', desc: 'Connexion exclusive via Microsoft OFPPT ou code email. Seuls les domaines scolaires autorisés ont accès.' },
    { icon: <Icons.Upload/>, title: 'Génération IA', desc: 'Téléversez un cours en PDF et obtenez un quiz complet en quelques secondes, prêt à être relu et publié.' },
  ]

  const testimonials = [
    { name: 'Mme. Rachida Zahra', role: 'Enseignante — OFPPT Casablanca', text: 'ClassLink m\'a permis de centraliser tous mes supports de cours et de suivre la progression de mes étudiants facilement.' },
    { name: 'Yassine Benali', role: 'Étudiant TDI 2A', text: 'Accéder aux quiz et aux ressources depuis mon téléphone est vraiment pratique. Je n\'oublie plus aucune échéance.' },
  ]

  return (
    <div className="min-h-screen" style={{ background:'var(--background)' }}>
      {/* Nav */}
      <nav className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-[var(--border)]">
        <div className="max-w-6xl mx-auto px-5 md:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <Icons.Logo/>
            <span className="font-display text-lg font-semibold">ClassLink</span>
          </div>
          <div className="flex items-center gap-3">
            <span className="hidden sm:block text-xs text-[var(--muted-foreground)]">v1.0 · OFPPT</span>
            <Btn onClick={() => onNav('login')}>Se connecter</Btn>
          </div>
        </div>
      </nav>

      {/* Hero */}
      <section className="max-w-6xl mx-auto px-5 md:px-8 pt-16 pb-20 grid md:grid-cols-2 gap-12 items-center">
        <div>
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium mb-6"
            style={{ background:'rgba(232,130,12,0.1)', color:'var(--accent)' }}>
            <span className="w-1.5 h-1.5 rounded-full bg-[var(--accent)]"/>
            Plateforme officielle OFPPT
          </div>
          <h1 className="font-display text-4xl md:text-5xl font-semibold leading-tight mb-5">
            L'espace numérique<br/>de vos classes<br/>
            <span style={{ color:'var(--accent)' }}>OFPPT.</span>
          </h1>
          <p className="text-[var(--muted-foreground)] leading-relaxed mb-8 max-w-md">
            ClassLink connecte enseignants et étudiants dans un environnement pédagogique sécurisé. Cours, quiz, devoirs et suivi de progression — tout en un.
          </p>
          <div className="flex flex-col sm:flex-row gap-3">
            <Btn size="lg" onClick={() => onNav('login')}>Commencer maintenant</Btn>
            <Btn size="lg" variant="secondary" onClick={() => onNav('privacy')}>En savoir plus</Btn>
          </div>
          <p className="text-xs text-[var(--muted-foreground)] mt-4">Accès réservé aux domaines <code className="font-mono bg-[var(--muted)] px-1 rounded">ofppt-edu.ma</code></p>
        </div>

        {/* Hero visual */}
        <div className="hidden md:block">
          <div className="relative">
            <div className="rounded-2xl overflow-hidden shadow-2xl" style={{ background:'var(--sidebar)' }}>
              <div className="p-4 border-b border-white/10 flex items-center gap-3">
                <Icons.Logo/>
                <span className="text-white font-display font-semibold">ClassLink</span>
              </div>
              <div className="p-4 space-y-2">
                {['Développement Web — 28 étudiants','Algorithmique — 24 étudiants','Réseaux informatiques — 31 étudiants'].map(c => (
                  <div key={c} className="flex items-center gap-3 px-3 py-2.5 rounded-lg" style={{ background:'rgba(255,255,255,0.08)' }}>
                    <Icons.Class/>
                    <p className="text-white/80 text-xs">{c}</p>
                  </div>
                ))}
                <div className="flex items-center gap-3 px-3 py-2.5 rounded-lg mt-3" style={{ background:'rgba(232,130,12,0.15)' }}>
                  <Icons.Bell/>
                  <p className="text-xs" style={{ color:'#FDBA74' }}>3 demandes d'adhésion en attente</p>
                </div>
              </div>
            </div>
            {/* Floating card */}
            <div className="absolute -bottom-5 -right-5 bg-white rounded-xl shadow-xl p-4 w-40 border border-[var(--border)]">
              <p className="text-xs font-medium mb-2">Progression globale</p>
              <p className="text-2xl font-display font-semibold text-[var(--primary)]">78%</p>
              <div className="mt-2 h-1.5 rounded-full bg-[var(--muted)]">
                <div className="h-full rounded-full bg-[var(--primary)]" style={{ width:'78%' }}/>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Features */}
      <section className="bg-white border-y border-[var(--border)] py-16">
        <div className="max-w-6xl mx-auto px-5 md:px-8">
          <h2 className="font-display text-3xl font-semibold text-center mb-2">Tout ce dont vous avez besoin</h2>
          <p className="text-center text-[var(--muted-foreground)] text-sm mb-12">Une plateforme conçue pour les besoins réels des formateurs et apprenants OFPPT.</p>
          <div className="grid sm:grid-cols-2 md:grid-cols-3 gap-6">
            {features.map(f => (
              <div key={f.title} className="p-6 rounded-[var(--radius)] border border-[var(--border)] hover:border-[var(--primary)]/30 hover:shadow-md transition-all group">
                <div className="w-10 h-10 rounded-lg flex items-center justify-center mb-4 group-hover:bg-[var(--primary)] group-hover:text-white transition-all" style={{ background:'var(--secondary)', color:'var(--primary)' }}>
                  {f.icon}
                </div>
                <h3 className="font-semibold mb-2">{f.title}</h3>
                <p className="text-sm text-[var(--muted-foreground)] leading-relaxed">{f.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Testimonials */}
      <section className="py-16 max-w-6xl mx-auto px-5 md:px-8">
        <h2 className="font-display text-3xl font-semibold text-center mb-10">Ils utilisent ClassLink</h2>
        <div className="grid md:grid-cols-2 gap-6">
          {testimonials.map(t => (
            <div key={t.name} className="p-6 rounded-2xl border border-[var(--border)] bg-white">
              <div className="flex mb-2">{[...Array(5)].map((_,i)=><Icons.Star key={i}/>)}</div>
              <p className="text-sm leading-relaxed text-[var(--muted-foreground)] mb-4">« {t.text} »</p>
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style={{ background:'var(--accent)', color:'white' }}>
                  {t.name[0]}
                </div>
                <div>
                  <p className="text-sm font-medium">{t.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{t.role}</p>
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* CTA */}
      <section className="py-16" style={{ background:'var(--sidebar)' }}>
        <div className="max-w-2xl mx-auto px-5 text-center">
          <h2 className="font-display text-3xl font-semibold text-white mb-4">Prêt à commencer ?</h2>
          <p className="text-sm mb-8" style={{ color:'rgba(255,255,255,0.55)' }}>
            Connectez-vous avec votre compte Microsoft scolaire ou via code email.
          </p>
          <Btn size="lg" variant="accent" onClick={() => onNav('login')}>
            Se connecter à ClassLink
          </Btn>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-[var(--border)] bg-white">
        <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Icons.Logo/>
            <span className="font-display font-semibold">ClassLink</span>
            <span className="text-xs text-[var(--muted-foreground)]">v1.0</span>
          </div>
          <div className="flex items-center gap-4 text-xs text-[var(--muted-foreground)]">
            <button onClick={() => onNav('privacy')} className="hover:text-[var(--foreground)]">Confidentialité</button>
            <span>© 2026 OFPPT</span>
          </div>
        </div>
      </footer>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Part C — Accès refusé
══════════════════════════════════════════════════════════════ */
export function AccessDeniedScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center px-5 text-center" style={{ background:'var(--background)' }}>
      <div className="mb-6">
        <Icons.Logo/>
      </div>
      <div className="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6" style={{ background:'#FEE2E2' }}>
        <Icons.Shield/>
      </div>
      <h1 className="font-display text-3xl font-semibold mb-3">Accès refusé</h1>
      <p className="text-[var(--muted-foreground)] max-w-sm mb-2 leading-relaxed">
        Votre compte n'est pas autorisé à accéder à ClassLink.
      </p>
      <p className="text-sm text-[var(--muted-foreground)] max-w-sm mb-8 leading-relaxed">
        L'accès est réservé aux utilisateurs possédant un compte scolaire avec le domaine <code className="font-mono bg-[var(--muted)] px-1 rounded text-xs">ofppt-edu.ma</code>.
      </p>

      <div className="bg-white border border-[var(--border)] rounded-[var(--radius)] p-5 max-w-sm w-full mb-6 text-left space-y-2">
        <p className="text-sm font-medium">Que faire ?</p>
        {[
          'Assurez-vous d\'utiliser votre compte Microsoft scolaire OFPPT.',
          'Si vous avez un compte, attendez que votre rôle soit validé par un administrateur.',
          'Contactez l\'administration de votre établissement pour obtenir un accès.',
        ].map((s, i) => (
          <div key={i} className="flex items-start gap-2">
            <span className="w-4 h-4 rounded-full text-[10px] font-bold flex items-center justify-center shrink-0 mt-0.5" style={{ background:'var(--primary)', color:'white' }}>{i+1}</span>
            <p className="text-xs text-[var(--muted-foreground)]">{s}</p>
          </div>
        ))}
      </div>

      <Btn onClick={() => onNav('login')} variant="secondary">← Retour à la connexion</Btn>
      <p className="text-xs text-[var(--muted-foreground)] mt-8">© 2026 ClassLink — OFPPT</p>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Part C — Politique de confidentialité
══════════════════════════════════════════════════════════════ */
export function PrivacyScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const sections = [
    {
      title: '1. Collecte des données',
      body: 'ClassLink collecte uniquement les données nécessaires au fonctionnement de la plateforme : nom d\'affichage, rôle institutionnel, activité pédagogique (quiz, devoirs, progression). Aucune donnée personnelle sensible n\'est collectée sans consentement explicite.',
    },
    {
      title: '2. Utilisation des données',
      body: 'Les données collectées sont utilisées exclusivement pour : l\'authentification et la sécurité des comptes, le suivi pédagogique (progression, résultats), la génération de quiz par IA (PDF anonymisés, non conservés). Elles ne sont jamais vendues ni partagées avec des tiers commerciaux.',
    },
    {
      title: '3. Authentification Microsoft',
      body: 'ClassLink utilise Microsoft Entra ID pour l\'authentification OAuth. Seul l\'email institutionnel et le nom d\'affichage sont transmis. ClassLink n\'accède pas à vos mots de passe ni à vos données Microsoft personnelles.',
    },
    {
      title: '4. Conservation des données',
      body: 'Les données des utilisateurs sont conservées pour la durée de l\'année scolaire active, puis archivées pendant 3 ans conformément aux obligations légales marocaines. Les données d\'audit sont conservées 5 ans.',
    },
    {
      title: '5. Vos droits',
      body: 'Conformément à la loi 09-08 relative à la protection des données personnelles au Maroc, vous disposez d\'un droit d\'accès, de rectification et de suppression de vos données. Pour exercer ces droits, contactez l\'administration de votre établissement OFPPT.',
    },
    {
      title: '6. Sécurité',
      body: 'Toutes les communications sont chiffrées via HTTPS. Les jetons d\'authentification expirent après 8 heures. Un journal d\'audit complet enregistre toutes les actions sensibles sur la plateforme.',
    },
    {
      title: '7. Contact',
      body: 'Pour toute question relative à la confidentialité, contactez l\'administration de votre centre de formation OFPPT. ClassLink est opéré par l\'OFPPT dans le cadre de sa mission de formation professionnelle.',
    },
  ]

  return (
    <div className="min-h-screen" style={{ background:'var(--background)' }}>
      {/* Nav */}
      <nav className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-[var(--border)]">
        <div className="max-w-3xl mx-auto px-5 md:px-8 h-16 flex items-center justify-between">
          <button onClick={() => onNav('home')} className="flex items-center gap-2.5 hover:opacity-80 transition-opacity">
            <Icons.Logo/>
            <span className="font-display text-lg font-semibold">ClassLink</span>
          </button>
          <Btn variant="secondary" onClick={() => onNav('login')}>Se connecter</Btn>
        </div>
      </nav>

      <div className="max-w-3xl mx-auto px-5 md:px-8 py-12">
        <div className="mb-10">
          <p className="text-xs font-medium text-[var(--muted-foreground)] uppercase tracking-wider mb-2">Documents légaux</p>
          <h1 className="font-display text-4xl font-semibold mb-3">Politique de confidentialité</h1>
          <p className="text-sm text-[var(--muted-foreground)]">Dernière mise à jour : 1er septembre 2026 · ClassLink v1.0 — OFPPT</p>
        </div>

        <div className="bg-[var(--secondary)] border border-[var(--border)] rounded-[var(--radius)] p-4 mb-8">
          <p className="text-sm"><strong>Résumé :</strong> ClassLink collecte uniquement ce qui est nécessaire à votre expérience pédagogique. Vos données ne sont jamais vendues. L'accès est strictement limité aux utilisateurs OFPPT.</p>
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
          <p className="text-xs text-[var(--muted-foreground)]">© 2026 ClassLink — OFPPT. Tous droits réservés.</p>
          <div className="flex gap-3">
            <Btn variant="secondary" onClick={() => onNav('home')}>Accueil</Btn>
            <Btn onClick={() => onNav('login')}>Se connecter</Btn>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B1 — Connexion
══════════════════════════════════════════════════════════════ */
export function LoginScreen({ onLogin }: { onLogin: (role:'student'|'teacher'|'admin')=>void }) {
  const [mode, setMode] = useState<'main'|'otp-email'|'otp-code'>('main')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [pending, setPending] = useState(false)

  function sendOtp() { setPending(true); setTimeout(()=>{ setPending(false); setMode('otp-code') }, 900) }

  return (
    <div className="min-h-screen flex">
      {/* Left — brand panel */}
      <div className="hidden lg:flex flex-col justify-between w-1/2 p-12 relative overflow-hidden" style={{ background:'var(--sidebar)' }}>
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute -top-24 -left-24 w-96 h-96 rounded-full opacity-10" style={{ background:'radial-gradient(circle, var(--accent), transparent)' }}/>
          <div className="absolute bottom-0 right-0 w-72 h-72 rounded-full opacity-8" style={{ background:'radial-gradient(circle, #3B82F6, transparent)' }}/>
        </div>
        <div className="flex items-center gap-3 relative z-10"><Icons.Logo/><span className="font-display text-xl font-semibold text-white">ClassLink</span></div>
        <div className="relative z-10">
          <p className="text-xs font-medium uppercase tracking-widest mb-4" style={{ color:'rgba(232,130,12,0.9)' }}>Plateforme éducative OFPPT</p>
          <h1 className="font-display text-4xl font-semibold text-white leading-tight mb-5">Votre espace<br/>d'apprentissage<br/>numérique.</h1>
          <p style={{ color:'rgba(255,255,255,0.5)', lineHeight:'1.7' }} className="text-sm max-w-xs">Accédez à vos cours, quiz et ressources pédagogiques en un seul endroit.</p>
          <div className="mt-8 flex flex-wrap gap-4">
            {['Classes en ligne','Quiz interactifs','Ressources partagées'].map(t => (
              <div key={t} className="flex items-center gap-2">
                <div className="w-1.5 h-1.5 rounded-full" style={{ background:'var(--accent)' }}/>
                <span className="text-xs" style={{ color:'rgba(255,255,255,0.55)' }}>{t}</span>
              </div>
            ))}
          </div>
        </div>
        <p className="text-xs relative z-10" style={{ color:'rgba(255,255,255,0.25)' }}>© 2026 ClassLink — OFPPT</p>
      </div>

      {/* Right — form */}
      <div className="flex-1 flex flex-col items-center justify-center px-6 py-12" style={{ background:'var(--background)' }}>
        <div className="lg:hidden flex items-center gap-2 mb-8"><Icons.Logo/><span className="font-display text-xl font-semibold">ClassLink</span></div>
        <div className="w-full max-w-sm">
          {mode === 'main' && (
            <>
              <h2 className="font-display text-2xl font-semibold mb-1">Connexion</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-8">Utilisez votre compte scolaire OFPPT.</p>
              <button onClick={() => onLogin('teacher')}
                className="w-full flex items-center justify-center gap-3 px-5 py-3 rounded-[var(--radius)] border border-[var(--border)] bg-white hover:bg-[var(--muted)] font-medium text-sm transition-all mb-4">
                <svg className="w-5 h-5" viewBox="0 0 21 21" fill="none">
                  <rect x="0" y="0" width="10" height="10" fill="#F25022"/><rect x="11" y="0" width="10" height="10" fill="#7FBA00"/>
                  <rect x="0" y="11" width="10" height="10" fill="#00A4EF"/><rect x="11" y="11" width="10" height="10" fill="#FFB900"/>
                </svg>
                Se connecter avec Microsoft
              </button>
              <div className="flex items-center gap-3 my-5"><div className="flex-1 h-px bg-[var(--border)]"/><span className="text-xs text-[var(--muted-foreground)]">ou</span><div className="flex-1 h-px bg-[var(--border)]"/></div>
              <button onClick={() => setMode('otp-email')} className="w-full text-sm text-[var(--primary)] font-medium hover:underline">Connexion par code email →</button>
              <div className="mt-8 p-3 rounded-lg border border-dashed border-[var(--border)]">
                <p className="text-xs text-[var(--muted-foreground)] mb-2 font-medium">Démonstration</p>
                <div className="flex gap-2">
                  <button onClick={() => onLogin('student')} className="flex-1 text-xs py-1.5 rounded bg-[var(--secondary)] text-[var(--primary)] font-medium hover:bg-[var(--muted)]">Étudiant</button>
                  <button onClick={() => onLogin('teacher')} className="flex-1 text-xs py-1.5 rounded bg-[var(--secondary)] text-[var(--primary)] font-medium hover:bg-[var(--muted)]">Enseignant</button>
                  <button onClick={() => onLogin('admin')} className="flex-1 text-xs py-1.5 rounded bg-[var(--secondary)] text-[var(--primary)] font-medium hover:bg-[var(--muted)]">Admin</button>
                </div>
              </div>
            </>
          )}
          {mode === 'otp-email' && (
            <>
              <button onClick={() => setMode('main')} className="text-xs text-[var(--muted-foreground)] mb-6 hover:text-[var(--foreground)] flex items-center gap-1">← Retour</button>
              <h2 className="font-display text-2xl font-semibold mb-1">Code par email</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-8">Entrez votre adresse scolaire pour recevoir un code à 6 chiffres.</p>
              <div className="space-y-4">
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Adresse scolaire</label>
                  <input type="email" placeholder="prenom.nom@ofppt-edu.ma" value={email} onChange={e=>setEmail(e.target.value)}
                    className="w-full px-3.5 py-2.5 text-sm bg-white border border-[var(--border)] rounded-[var(--radius)] outline-none focus:ring-2 focus:ring-[var(--primary)]"/>
                </div>
                <Btn full onClick={sendOtp} disabled={pending||!email.includes('@')}>{pending?'Envoi en cours…':'Envoyer le code'}</Btn>
              </div>
            </>
          )}
          {mode === 'otp-code' && (
            <>
              <button onClick={() => setMode('otp-email')} className="text-xs text-[var(--muted-foreground)] mb-6 hover:text-[var(--foreground)] flex items-center gap-1">← Retour</button>
              <h2 className="font-display text-2xl font-semibold mb-1">Vérification</h2>
              <p className="text-sm text-[var(--muted-foreground)] mb-8">Saisissez le code à 6 chiffres envoyé à votre adresse scolaire. Valable 10 minutes.</p>
              <div className="space-y-4">
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">Code de vérification</label>
                  <input placeholder="123456" value={code} onChange={e=>setCode(e.target.value)}
                    className="w-full px-3.5 py-2.5 text-sm bg-white border border-[var(--border)] rounded-[var(--radius)] outline-none focus:ring-2 focus:ring-[var(--primary)]"/>
                </div>
                <Btn full onClick={() => onLogin('student')} disabled={code.length<4}>Vérifier le code</Btn>
                <button className="text-xs text-[var(--muted-foreground)] hover:underline w-full text-center">Renvoyer le code</button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
