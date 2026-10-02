import { useState } from 'react'
import { Btn, Badge, Card, Input, Select, StatTile, ProgressBar, Avatar, Toggle, Alert, PageHeader, Tabs, Icons } from '../components/UI'
import type { Screen } from '../components/UI'

/* ══════════════════════════════════════════════════════════════
   Admin shell — tabs shared across admin section
══════════════════════════════════════════════════════════════ */
function AdminTabs({ active, onNav }: { active: Screen; onNav: (s:Screen)=>void }) {
  const tabs = [
    { id: 'admin-overview' as Screen, label: 'Vue d\'ensemble' },
    { id: 'admin-users'    as Screen, label: 'Utilisateurs' },
    { id: 'admin-classes'  as Screen, label: 'Classes' },
    { id: 'admin-ai'       as Screen, label: 'Paramètres IA' },
    { id: 'admin-audit'    as Screen, label: 'Journal d\'audit' },
  ]
  return (
    <div className="flex gap-1 p-1 rounded-[var(--radius)] bg-[var(--muted)] mb-6 overflow-x-auto">
      {tabs.map(t => (
        <button key={t.id} onClick={() => onNav(t.id)}
          className={`flex-shrink-0 px-4 py-1.5 text-sm font-medium rounded-lg transition-all ${active===t.id?'bg-white shadow-sm text-[var(--foreground)]':'text-[var(--muted-foreground)] hover:text-[var(--foreground)]'}`}>
          {t.label}
        </button>
      ))}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Admin — Vue d'ensemble
══════════════════════════════════════════════════════════════ */
export function AdminOverviewScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const stats = [
    { label:'Utilisateurs actifs', value:'347', color:'var(--primary)', sub:'+12 ce mois' },
    { label:'Classes actives', value:'42', color:'#16A34A', sub:'3 archivées' },
    { label:'Quiz passés aujourd\'hui', value:'89', color:'var(--accent)', sub:'↑ 23% vs hier' },
    { label:'Rôles en attente', value:'5', color:'#7C3AED', sub:'Besoin de validation' },
  ]

  const recentActivity = [
    { action:'Nouvel utilisateur — rôle étudiant détecté', time:'Il y a 5 min', severity:'info' },
    { action:'Classe "IR 3B" archivée par M. Bakkali', time:'Il y a 1 h', severity:'warning' },
    { action:'Tentative de connexion domaine externe refusée', time:'Il y a 2 h', severity:'danger' },
    { action:'Quota IA Fournisseur 1 atteint — bascule vers Fournisseur 2', time:'Il y a 4 h', severity:'warning' },
    { action:'Mise à jour déployée — v1.2.3', time:'Hier', severity:'info' },
  ]

  const aiStatus = [
    { name:'Fournisseur IA 1', status:'Actif', usage:87, limit:100 },
    { name:'Fournisseur IA 2', status:'Actif', usage:23, limit:100 },
  ]

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <PageHeader title="Vue d'ensemble" subtitle="ClassLink 1.0 — Tableau de bord super admin"/>
      <AdminTabs active="admin-overview" onNav={onNav}/>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
        {stats.map(s => <StatTile key={s.label} label={s.label} value={s.value} color={s.color} sub={s.sub}/>)}
      </div>

      <div className="grid md:grid-cols-5 gap-5">
        <div className="md:col-span-3">
          <h2 className="font-semibold text-sm mb-3">Activité récente</h2>
          <Card className="divide-y divide-[var(--border)]">
            {recentActivity.map((a, i) => {
              const dot = a.severity==='danger'?'#DC2626':a.severity==='warning'?'var(--accent)':'var(--primary)'
              return (
                <div key={i} className="px-4 py-3 flex items-start gap-3">
                  <span className="w-2 h-2 rounded-full mt-1.5 shrink-0" style={{ background:dot }}/>
                  <div>
                    <p className="text-xs leading-relaxed">{a.action}</p>
                    <p className="text-[10px] text-[var(--muted-foreground)] mt-0.5">{a.time}</p>
                  </div>
                </div>
              )
            })}
          </Card>
        </div>

        <div className="md:col-span-2 space-y-3">
          <h2 className="font-semibold text-sm mb-3">État des fournisseurs IA</h2>
          {aiStatus.map(ai => (
            <Card key={ai.name} className="p-4">
              <div className="flex items-center justify-between mb-2">
                <p className="font-medium text-sm">{ai.name}</p>
                <Badge label={ai.status} color="green"/>
              </div>
              <div className="flex items-center gap-2">
                <div className="flex-1"><ProgressBar value={(ai.usage/ai.limit)*100} color={ai.usage>80?'#DC2626':'var(--primary)'}/></div>
                <span className="text-xs text-[var(--muted-foreground)]">{ai.usage}/{ai.limit}</span>
              </div>
              <p className="text-xs text-[var(--muted-foreground)] mt-1">Quotas journaliers</p>
            </Card>
          ))}
          <Btn full variant="secondary" onClick={() => onNav('admin-ai')}>Gérer l'IA →</Btn>
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Admin — Utilisateurs
══════════════════════════════════════════════════════════════ */
export function AdminUsersScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState('all')
  const [users, setUsers] = useState([
    { id:1, name:'Yassine Benali', role:'student', active:true, lastLogin:'30 sept.' },
    { id:2, name:'Sara Idrissi', role:'student', active:true, lastLogin:'30 sept.' },
    { id:3, name:'M. Alaoui', role:'teacher', active:true, lastLogin:'30 sept.' },
    { id:4, name:'Mme. Zahra', role:'teacher', active:true, lastLogin:'29 sept.' },
    { id:5, name:'Karim Tahir', role:'student', active:false, lastLogin:'1 sept.' },
    { id:6, name:'Amine Berrada', role:'pending', active:true, lastLogin:'30 sept.' },
    { id:7, name:'Rachid Oulhaj', role:'student', active:true, lastLogin:'28 sept.' },
  ])

  const roleColor = { student:'blue', teacher:'purple', admin:'orange', pending:'default' } as const

  const filtered = users.filter(u => {
    const matchSearch = u.name.toLowerCase().includes(search.toLowerCase())
    const matchFilter = filter === 'all' || u.role === filter
    return matchSearch && matchFilter
  })

  function toggleActive(id: number) {
    setUsers(us => us.map(u => u.id===id ? {...u, active:!u.active} : u))
  }

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <PageHeader title="Utilisateurs" subtitle={`${users.length} comptes enregistrés`}/>
      <AdminTabs active="admin-users" onNav={onNav}/>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3 mb-5">
        <div className="relative flex-1">
          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted-foreground)]"><Icons.Search/></span>
          <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Rechercher un utilisateur…"
            className="w-full pl-9 pr-3 py-2.5 text-sm bg-white border border-[var(--border)] rounded-[var(--radius)] outline-none focus:ring-2 focus:ring-[var(--primary)]"/>
        </div>
        <Select value={filter} onChange={setFilter} options={[
          {value:'all',label:'Tous les rôles'},
          {value:'student',label:'Étudiants'},
          {value:'teacher',label:'Enseignants'},
          {value:'pending',label:'En attente'},
        ]}/>
      </div>

      <Card>
        <div className="px-4 py-2.5 border-b border-[var(--border)] grid grid-cols-4 gap-2 text-xs font-medium text-[var(--muted-foreground)]">
          <span>Utilisateur</span><span>Rôle</span><span>Dernière connexion</span><span>État</span>
        </div>
        {filtered.map(u => (
          <div key={u.id} className="px-4 py-3 border-b border-[var(--border)] last:border-0 grid grid-cols-4 gap-2 items-center">
            <div className="flex items-center gap-2">
              <Avatar name={u.name} size="sm"/>
              <span className="text-sm font-medium truncate">{u.name}</span>
            </div>
            <Badge label={u.role==='student'?'Étudiant':u.role==='teacher'?'Enseignant':u.role==='pending'?'En attente':'Admin'}
              color={roleColor[u.role as keyof typeof roleColor]||'default'}/>
            <span className="text-xs text-[var(--muted-foreground)]">{u.lastLogin}</span>
            <div className="flex items-center gap-2">
              <Toggle checked={u.active} onChange={() => toggleActive(u.id)}/>
              <span className="text-xs text-[var(--muted-foreground)]">{u.active?'Actif':'Désactivé'}</span>
            </div>
          </div>
        ))}
      </Card>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Admin — Classes
══════════════════════════════════════════════════════════════ */
export function AdminClassesScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const classes = [
    { name:'Développement Web', teacher:'M. Alaoui', group:'TDI 2A', students:28, status:'active', quizzes:3 },
    { name:'Algorithmique', teacher:'Mme. Zahra', group:'TDI 1A', students:24, status:'active', quizzes:2 },
    { name:'Réseaux informatiques', teacher:'M. Bakkali', group:'IR 2A', students:31, status:'active', quizzes:4 },
    { name:'Comptabilité générale', teacher:'Mme. Moha', group:'CG 1A', students:19, status:'archived', quizzes:1 },
  ]

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <PageHeader title="Classes" subtitle={`${classes.length} classes — ${classes.filter(c=>c.status==='active').length} actives`}/>
      <AdminTabs active="admin-classes" onNav={onNav}/>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <StatTile label="Classes actives" value="3" color="var(--primary)"/>
        <StatTile label="Classes archivées" value="1" color="var(--muted-foreground)"/>
        <StatTile label="Total étudiants" value="102" color="#7C3AED"/>
        <StatTile label="Quiz au total" value="10" color="#16A34A"/>
      </div>

      <Card>
        <div className="px-4 py-2.5 border-b border-[var(--border)] grid grid-cols-5 gap-2 text-xs font-medium text-[var(--muted-foreground)]">
          <span className="col-span-2">Classe</span><span>Enseignant</span><span>Étudiants</span><span>État</span>
        </div>
        {classes.map(c => (
          <div key={c.name} className="px-4 py-3 border-b border-[var(--border)] last:border-0 grid grid-cols-5 gap-2 items-center">
            <div className="col-span-2">
              <p className="font-medium text-sm">{c.name}</p>
              <p className="text-xs text-[var(--muted-foreground)]">{c.group}</p>
            </div>
            <p className="text-sm">{c.teacher}</p>
            <p className="text-sm">{c.students}</p>
            <Badge label={c.status==='active'?'Active':'Archivée'} color={c.status==='active'?'green':'default'}/>
          </div>
        ))}
      </Card>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Admin — Paramètres IA
══════════════════════════════════════════════════════════════ */
export function AdminAiScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const [providers, setProviders] = useState([
    { id:1, name:'Fournisseur IA 1', enabled:true, priority:1, limit:100, used:87 },
    { id:2, name:'Fournisseur IA 2', enabled:true, priority:2, limit:100, used:23 },
    { id:3, name:'Fournisseur IA 3', enabled:false, priority:3, limit:50, used:0 },
  ])

  function toggleProvider(id: number) {
    setProviders(ps => ps.map(p => p.id===id ? {...p, enabled:!p.enabled} : p))
  }

  return (
    <div className="px-5 md:px-8 py-6 max-w-4xl mx-auto">
      <PageHeader title="Paramètres IA" subtitle="Gérez les fournisseurs d'IA et les quotas de génération de quiz."/>
      <AdminTabs active="admin-ai" onNav={onNav}/>

      <Alert message="Les fournisseurs sont utilisés par ordre de priorité. En cas d'échec, le suivant est automatiquement essayé." type="info"/>

      <div className="mt-5 space-y-3">
        {providers.map(p => (
          <Card key={p.id} className="p-5">
            <div className="flex items-start justify-between gap-3 mb-4">
              <div>
                <div className="flex items-center gap-2 mb-0.5">
                  <p className="font-medium">{p.name}</p>
                  <Badge label={`Priorité ${p.priority}`} color={p.priority===1?'blue':'default'}/>
                  {!p.enabled && <Badge label="Désactivé" color="red"/>}
                </div>
                <p className="text-xs text-[var(--muted-foreground)]">API compatible OpenAI · Quota journalier : {p.limit} requêtes</p>
              </div>
              <Toggle checked={p.enabled} onChange={() => toggleProvider(p.id)}/>
            </div>

            {p.enabled && (
              <div className="space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-[var(--muted-foreground)]">Utilisation aujourd'hui</span>
                  <span className="font-medium">{p.used}/{p.limit} requêtes</span>
                </div>
                <ProgressBar value={(p.used/p.limit)*100} color={p.used/p.limit>0.8?'#DC2626':'var(--primary)'}/>
              </div>
            )}

            <div className="flex gap-2 mt-4">
              <Btn size="sm" variant="secondary">Tester la connexion</Btn>
              <Btn size="sm" variant="ghost">Réinitialiser le quota</Btn>
            </div>
          </Card>
        ))}
      </div>

      <Card className="p-5 mt-5">
        <p className="font-medium mb-3">Règles globales de génération</p>
        <div className="space-y-3">
          <Input label="Taille max du fichier PDF (Mo)" type="number" placeholder="20"/>
          <Input label="Nombre max de questions générées" type="number" placeholder="30"/>
          <Toggle checked={true} onChange={()=>{}} label="Valider automatiquement le JSON généré"/>
        </div>
        <Btn className="mt-4">Enregistrer</Btn>
      </Card>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Admin — Journal d'audit
══════════════════════════════════════════════════════════════ */
export function AdminAuditScreen({ onNav }: { onNav: (s:Screen)=>void }) {
  const [filter, setFilter] = useState('all')

  const logs = [
    { action:'auth.login', user:'Yassine Benali', ip:'105.73.12.88', time:'30 sept. 2026 — 09:14', detail:'Connexion Microsoft réussie' },
    { action:'class.join_request', user:'Amine Berrada', ip:'105.73.45.21', time:'30 sept. 2026 — 09:02', detail:'Demande pour Développement Web' },
    { action:'quiz.publish', user:'M. Alaoui', ip:'197.230.5.17', time:'29 sept. 2026 — 17:45', detail:'Quiz "HTML/CSS Avancé" publié' },
    { action:'auth.failed', user:'Inconnu', ip:'192.168.1.100', time:'29 sept. 2026 — 14:30', detail:'Domaine externe refusé' },
    { action:'auth.logout', user:'Mme. Zahra', ip:'105.73.8.55', time:'29 sept. 2026 — 13:00', detail:'Déconnexion manuelle' },
    { action:'class.archive', user:'M. Bakkali', ip:'105.73.99.4', time:'28 sept. 2026 — 11:20', detail:'Classe "IR 3B" archivée' },
    { action:'ai.generate', user:'M. Alaoui', ip:'197.230.5.17', time:'27 sept. 2026 — 10:05', detail:'Quiz IA généré pour Réseaux' },
    { action:'user.role_change', user:'Super Admin', ip:'10.0.0.1', time:'26 sept. 2026 — 09:00', detail:'M. Alaoui → rôle enseignant' },
  ]

  const filtered = filter === 'all' ? logs : logs.filter(l => l.action.startsWith(filter))

  const actionColor = (a: string) => {
    if (a.startsWith('auth.failed')) return 'red'
    if (a.startsWith('auth')) return 'green'
    if (a.startsWith('quiz')) return 'blue'
    if (a.startsWith('ai')) return 'purple'
    return 'default'
  }

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <PageHeader title="Journal d'audit" subtitle="Historique de toutes les actions sur la plateforme."/>
      <AdminTabs active="admin-audit" onNav={onNav}/>

      <div className="flex flex-col sm:flex-row gap-3 mb-5">
        <Select value={filter} onChange={setFilter} options={[
          {value:'all',label:'Toutes les actions'},
          {value:'auth',label:'Authentification'},
          {value:'quiz',label:'Quiz'},
          {value:'class',label:'Classes'},
          {value:'ai',label:'IA'},
          {value:'user',label:'Utilisateurs'},
        ]}/>
        <input placeholder="Rechercher par utilisateur ou IP…"
          className="flex-1 px-3.5 py-2.5 text-sm bg-white border border-[var(--border)] rounded-[var(--radius)] outline-none focus:ring-2 focus:ring-[var(--primary)]"/>
      </div>

      <Card>
        <div className="px-4 py-2.5 border-b border-[var(--border)] grid grid-cols-4 gap-2 text-xs font-medium text-[var(--muted-foreground)]">
          <span>Action</span><span>Utilisateur</span><span>Date</span><span>IP</span>
        </div>
        {filtered.map((l, i) => (
          <div key={i} className="px-4 py-3 border-b border-[var(--border)] last:border-0 grid grid-cols-4 gap-2 items-start">
            <div>
              <Badge label={l.action} color={actionColor(l.action) as any}/>
              <p className="text-xs text-[var(--muted-foreground)] mt-1">{l.detail}</p>
            </div>
            <p className="text-sm">{l.user}</p>
            <p className="text-xs text-[var(--muted-foreground)]">{l.time}</p>
            <code className="text-xs font-mono text-[var(--muted-foreground)]">{l.ip}</code>
          </div>
        ))}
      </Card>
    </div>
  )
}
