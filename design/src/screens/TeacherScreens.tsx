import { useState } from 'react'
import { Btn, Badge, Card, Input, Textarea, Select, Tabs, StatTile, ProgressBar, Avatar, Toggle, Alert, PageHeader, Icons } from '../components/UI'
import type { Screen } from '../components/UI'

/* ══════════════════════════════════════════════════════════════
   B7 — Tableau de bord enseignant
══════════════════════════════════════════════════════════════ */
export function TeacherDashboard({ onNav }: { onNav: (s: Screen) => void }) {
  const classes = [
    { name: 'Développement Web', group: 'TDI 2A', students: 28, pending: 2, quizzes: 3, color: '#1A3A6B' },
    { name: 'Algorithmique', group: 'TDI 1A', students: 24, pending: 1, quizzes: 2, color: '#7C3AED' },
    { name: 'Réseaux informatiques', group: 'IR 2A', students: 31, pending: 0, quizzes: 4, color: '#16A34A' },
  ]
  const activity = [
    { text: 'Amine Berrada a demandé à rejoindre Développement Web', time: 'Il y a 12 min', type: 'join' },
    { text: 'Sara Idrissi a soumis le devoir TP2', time: 'Il y a 1 h', type: 'submit' },
    { text: 'Quiz HTML/CSS — 18 tentatives reçues', time: 'Il y a 3 h', type: 'quiz' },
    { text: 'Fatima Zahra a demandé à rejoindre Algorithmique', time: 'Hier', type: 'join' },
  ]

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <PageHeader
        title="Tableau de bord"
        subtitle="Mercredi 30 septembre 2026"
        actions={<Btn onClick={() => onNav('create-class')}><Icons.Plus/>Nouvelle classe</Btn>}
      />

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
        <StatTile label="Classes actives" value="3" color="var(--primary)"/>
        <StatTile label="Étudiants au total" value="83" color="#7C3AED"/>
        <StatTile label="Demandes en attente" value="3" color="var(--accent)"/>
        <StatTile label="Quiz publiés" value="9" color="#16A34A"/>
      </div>

      <div className="grid md:grid-cols-5 gap-5">
        {/* Classes */}
        <div className="md:col-span-3 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-sm">Mes classes</h2>
            <button onClick={() => onNav('create-class')} className="text-xs text-[var(--primary)] font-medium hover:underline">+ Créer</button>
          </div>
          {classes.map(c => (
            <Card key={c.name} className="p-4" onClick={() => onNav('manage-class')}>
              <div className="flex items-start justify-between mb-3">
                <div>
                  <p className="font-medium text-sm">{c.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{c.group} · {c.students} étudiants</p>
                </div>
                {c.pending > 0 && <Badge label={`${c.pending} en attente`} color="orange"/>}
              </div>
              <div className="flex items-center gap-4 text-xs text-[var(--muted-foreground)]">
                <span className="flex items-center gap-1"><Icons.Quiz/>{c.quizzes} quiz</span>
                <span className="flex items-center gap-1"><Icons.Users/>{c.students} membres</span>
              </div>
            </Card>
          ))}
        </div>

        {/* Activity feed */}
        <div className="md:col-span-2 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-sm">Activité récente</h2>
            <button onClick={() => onNav('requests')} className="text-xs text-[var(--primary)] font-medium hover:underline">Demandes →</button>
          </div>
          <Card className="divide-y divide-[var(--border)]">
            {activity.map((a, i) => (
              <div key={i} className="px-4 py-3">
                <p className="text-xs leading-relaxed">{a.text}</p>
                <p className="text-[10px] text-[var(--muted-foreground)] mt-1">{a.time}</p>
              </div>
            ))}
          </Card>

          <Card className="p-4">
            <p className="font-medium text-sm mb-3">Actions rapides</p>
            <div className="grid grid-cols-2 gap-2">
              {[
                { label: 'Créer un quiz', action: () => onNav('create-quiz') },
                { label: 'Quiz par IA', action: () => onNav('ai-quiz') },
                { label: 'Demandes', action: () => onNav('requests') },
                { label: 'Progression', action: () => onNav('class-progress') },
              ].map(a => (
                <button key={a.label} onClick={a.action}
                  className="px-3 py-2 text-xs font-medium rounded-lg bg-[var(--secondary)] text-[var(--primary)] hover:bg-[var(--muted)] transition-all text-left">
                  {a.label}
                </button>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B8 — Créer une classe
══════════════════════════════════════════════════════════════ */
export function CreateClassScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [form, setForm] = useState({ name: '', subject: '', group: '', year: '2025-2026' })
  const [created, setCreated] = useState(false)
  const code = 'TDI-' + Math.floor(100 + Math.random() * 900)

  function set(k: keyof typeof form) { return (v: string) => setForm(f => ({ ...f, [k]: v })) }

  if (created) return (
    <div className="max-w-lg mx-auto px-5 md:px-8 py-10">
      <Card className="p-8 text-center">
        <div className="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-5 bg-green-50">
          <Icons.Check/>
        </div>
        <h2 className="font-display text-xl font-semibold mb-1">Classe créée !</h2>
        <p className="text-sm text-[var(--muted-foreground)] mb-6">Partagez ce code avec vos étudiants pour qu'ils puissent rejoindre la classe.</p>
        <div className="inline-flex items-center gap-3 px-5 py-3 rounded-[var(--radius)] mb-6"
          style={{ background:'var(--secondary)', border:'2px dashed var(--primary)' }}>
          <span className="font-display text-2xl font-semibold text-[var(--primary)] tracking-widest">{code}</span>
          <button className="text-xs text-[var(--muted-foreground)] hover:text-[var(--primary)] border border-[var(--border)] px-2 py-1 rounded">Copier</button>
        </div>
        <div className="flex gap-2">
          <Btn full variant="secondary" onClick={() => onNav('manage-class')}>Gérer la classe</Btn>
          <Btn full onClick={() => onNav('teacher-dashboard')}>Tableau de bord</Btn>
        </div>
      </Card>
    </div>
  )

  return (
    <div className="max-w-lg mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Créer une classe" subtitle="Définissez les informations de base de votre nouvelle classe." back={() => onNav('teacher-dashboard')}/>
      <Card className="p-6">
        <div className="flex flex-col gap-4">
          <Input label="Nom de la classe" placeholder="ex. Développement Web Full Stack" value={form.name} onChange={set('name')}/>
          <Input label="Matière / Module" placeholder="ex. TDI, IR, Comptabilité" value={form.subject} onChange={set('subject')}/>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Groupe" placeholder="ex. TDI 2A" value={form.group} onChange={set('group')}/>
            <Select label="Année scolaire" value={form.year} onChange={set('year')} options={[
              { value: '2025-2026', label: '2025–2026' },
              { value: '2026-2027', label: '2026–2027' },
            ]}/>
          </div>
          <div className="pt-2">
            <Btn full onClick={() => setCreated(true)} disabled={!form.name || !form.subject}>
              Créer la classe
            </Btn>
          </div>
        </div>
      </Card>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B9 — Gestion de classe (enseignant)
══════════════════════════════════════════════════════════════ */
export function ManageClassScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [tab, setTab] = useState('apercu')
  const tabs = [
    { id: 'apercu', label: 'Aperçu' },
    { id: 'demandes', label: 'Demandes' },
    { id: 'membres', label: 'Membres' },
    { id: 'annonces', label: 'Annonces' },
    { id: 'ressources', label: 'Ressources' },
    { id: 'quiz', label: 'Quiz' },
    { id: 'devoirs', label: 'Devoirs' },
    { id: 'parametres', label: 'Paramètres' },
  ]

  const [members, setMembers] = useState([
    { id: 1, name: 'Yassine Benali', status: 'accepted', joined: '10 sept.' },
    { id: 2, name: 'Sara Idrissi', status: 'accepted', joined: '10 sept.' },
    { id: 3, name: 'Karim Tahir', status: 'accepted', joined: '11 sept.' },
    { id: 4, name: 'Nadia Ouali', status: 'accepted', joined: '12 sept.' },
  ])

  const [requests, setRequests] = useState([
    { id: 5, name: 'Amine Berrada', date: '30 sept.' },
    { id: 6, name: 'Fatima Zahra Haddou', date: '29 sept.' },
  ])

  const [joinEnabled, setJoinEnabled] = useState(true)

  function decide(id: number, accept: boolean) {
    const req = requests.find(r => r.id === id)!
    setRequests(rs => rs.filter(r => r.id !== id))
    if (accept) setMembers(ms => [...ms, { id, name: req.name, status: 'accepted', joined: 'Aujourd\'hui' }])
  }

  const annonces = [
    { title: 'TP3 disponible', body: 'Les consignes du TP3 sont en ligne dans l\'onglet Ressources.', date: '28 sept.', pinned: true },
    { title: 'Quiz de mi-semestre', body: 'Rappel : le quiz aura lieu le 5 octobre.', date: '25 sept.', pinned: false },
  ]
  const ressources = [
    { title: 'Cours 04 — JavaScript ES6', type: 'PDF', chapter: 'Chapitre 4' },
    { title: 'MDN Web Docs', type: 'Lien', chapter: 'Référence' },
    { title: 'Cours 03 — CSS Grid', type: 'PDF', chapter: 'Chapitre 3' },
  ]
  const quizzes = [
    { title: 'Quiz HTML/CSS Avancé', status: 'published', attempts: 18, avg: '74%' },
    { title: 'Quiz Introduction HTML', status: 'published', attempts: 25, avg: '81%' },
    { title: 'Quiz JavaScript ES6', status: 'draft', attempts: 0, avg: '—' },
  ]
  const devoirs = [
    { title: 'TP2 — Mise en page responsive', due: '15 oct.', submitted: 12, total: 28 },
    { title: 'TP1 — Portfolio HTML', due: '1 oct.', submitted: 26, total: 28 },
  ]

  return (
    <div className="max-w-4xl mx-auto px-5 md:px-8 py-6">
      <div className="mb-5">
        <button onClick={() => onNav('teacher-dashboard')} className="text-xs text-[var(--muted-foreground)] mb-2 hover:text-[var(--foreground)] flex items-center gap-1">← Mes classes</button>
        <div className="flex items-start justify-between">
          <div>
            <h1 className="font-display text-2xl font-semibold">Développement Web</h1>
            <p className="text-sm text-[var(--muted-foreground)] mt-0.5">TDI 2025–2026 · {members.length} membres · Code : <code className="font-mono text-[var(--primary)]">TDI-202</code></p>
          </div>
          <Badge label="Active" color="green"/>
        </div>
      </div>

      <div className="mb-5 overflow-x-auto"><Tabs tabs={tabs} active={tab} onChange={setTab}/></div>

      {/* Aperçu */}
      {tab === 'apercu' && (
        <div className="grid sm:grid-cols-2 md:grid-cols-3 gap-3">
          <StatTile label="Membres" value={String(members.length)} color="var(--primary)"/>
          <StatTile label="Demandes en attente" value={String(requests.length)} color="var(--accent)"/>
          <StatTile label="Quiz publiés" value="2" color="#16A34A"/>
          <StatTile label="Devoirs actifs" value="2" color="#7C3AED"/>
          <StatTile label="Moyenne classe" value="77%" color="var(--primary)"/>
          <StatTile label="Taux de remise devoirs" value="88%" color="#16A34A"/>
        </div>
      )}

      {/* Demandes */}
      {tab === 'demandes' && (
        <div className="space-y-2.5">
          {requests.length === 0 && <Card className="p-8 text-center"><p className="text-sm text-[var(--muted-foreground)]">Aucune demande en attente.</p></Card>}
          {requests.map(r => (
            <Card key={r.id} className="p-4 flex items-center gap-3">
              <Avatar name={r.name} color="var(--secondary)" textColor="var(--primary)"/>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm">{r.name}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{r.date}</p>
              </div>
              <div className="flex gap-2">
                <button onClick={() => decide(r.id, false)}
                  className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-[var(--border)] hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition-all">
                  <Icons.X/>Refuser
                </button>
                <button onClick={() => decide(r.id, true)}
                  className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-white rounded-lg transition-all" style={{ background:'#16A34A' }}>
                  <Icons.Check/>Accepter
                </button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Membres */}
      {tab === 'membres' && (
        <div className="space-y-2">
          {members.map(m => (
            <Card key={m.id} className="px-4 py-3 flex items-center gap-3">
              <Avatar name={m.name}/>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm">{m.name}</p>
                <p className="text-xs text-[var(--muted-foreground)]">Rejoint le {m.joined}</p>
              </div>
              <Badge label="Accepté" color="green"/>
              <button className="p-1.5 rounded hover:bg-red-50 text-[var(--muted-foreground)] hover:text-red-600 transition-all ml-1">
                <Icons.Trash/>
              </button>
            </Card>
          ))}
        </div>
      )}

      {/* Annonces */}
      {tab === 'annonces' && (
        <div className="space-y-3">
          <div className="flex justify-end">
            <Btn onClick={() => onNav('publish-content')}><Icons.Plus/>Nouvelle annonce</Btn>
          </div>
          {annonces.map(a => (
            <Card key={a.title} className="p-5">
              <div className="flex items-start justify-between gap-3">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    {a.pinned && <Badge label="Épinglé" color="orange"/>}
                    <h3 className="font-medium text-sm">{a.title}</h3>
                  </div>
                  <p className="text-sm text-[var(--muted-foreground)]">{a.body}</p>
                  <p className="text-xs text-[var(--muted-foreground)] mt-2">{a.date}</p>
                </div>
                <button className="p-1.5 text-[var(--muted-foreground)] hover:text-[var(--foreground)]"><Icons.Pencil/></button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Ressources */}
      {tab === 'ressources' && (
        <div className="space-y-2.5">
          <div className="flex justify-end">
            <Btn onClick={() => onNav('publish-content')}><Icons.Plus/>Ajouter une ressource</Btn>
          </div>
          {ressources.map(r => (
            <Card key={r.title} className="p-4 flex items-center gap-4">
              <div className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                style={{ background: r.type==='PDF'?'#FEE2E2':'#DBEAFE' }}>
                <Icons.Book/>
              </div>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm truncate">{r.title}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{r.chapter}</p>
              </div>
              <Badge label={r.type} color={r.type==='PDF'?'red':'blue'}/>
              <button className="p-1.5 text-[var(--muted-foreground)] hover:text-red-500"><Icons.Trash/></button>
            </Card>
          ))}
        </div>
      )}

      {/* Quiz */}
      {tab === 'quiz' && (
        <div className="space-y-3">
          <div className="flex gap-2 justify-end">
            <Btn variant="secondary" onClick={() => onNav('ai-quiz')}><Icons.Cpu/>Générer par IA</Btn>
            <Btn onClick={() => onNav('create-quiz')}><Icons.Plus/>Créer manuellement</Btn>
          </div>
          {quizzes.map(q => (
            <Card key={q.title} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
              <div className="flex-1">
                <p className="font-medium text-sm">{q.title}</p>
                <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{q.attempts} tentatives · Moyenne : {q.avg}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge label={q.status==='published'?'Publié':'Brouillon'} color={q.status==='published'?'green':'default'}/>
                <Btn size="sm" variant="secondary"><Icons.Pencil/>Modifier</Btn>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Devoirs */}
      {tab === 'devoirs' && (
        <div className="space-y-3">
          {devoirs.map(d => (
            <Card key={d.title} className="p-5">
              <div className="flex items-start justify-between gap-3 mb-3">
                <div>
                  <p className="font-medium text-sm">{d.title}</p>
                  <p className="text-xs text-[var(--muted-foreground)] mt-0.5">Date limite : {d.due}</p>
                </div>
                <Btn size="sm" onClick={() => onNav('grade-assignments')}>Corriger</Btn>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-xs text-[var(--muted-foreground)]">{d.submitted}/{d.total} remis</span>
                <div className="flex-1"><ProgressBar value={(d.submitted/d.total)*100}/></div>
                <span className="text-xs font-medium">{Math.round((d.submitted/d.total)*100)}%</span>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Paramètres */}
      {tab === 'parametres' && (
        <Card className="p-6 space-y-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="font-medium text-sm">Adhésions ouvertes</p>
              <p className="text-xs text-[var(--muted-foreground)]">Permettre aux étudiants d'envoyer des demandes.</p>
            </div>
            <Toggle checked={joinEnabled} onChange={setJoinEnabled}/>
          </div>
          <div className="border-t border-[var(--border)] pt-5">
            <p className="font-medium text-sm mb-2">Code d'invitation</p>
            <div className="flex items-center gap-2">
              <code className="flex-1 px-3 py-2 bg-[var(--secondary)] rounded-lg text-sm font-mono text-[var(--primary)]">TDI-202</code>
              <Btn size="sm" variant="secondary">Copier</Btn>
              <Btn size="sm" variant="ghost">Régénérer</Btn>
            </div>
          </div>
          <div className="border-t border-[var(--border)] pt-5 flex justify-between items-center">
            <div>
              <p className="font-medium text-sm text-red-600">Archiver la classe</p>
              <p className="text-xs text-[var(--muted-foreground)]">Les étudiants ne pourront plus accéder à la classe.</p>
            </div>
            <Btn size="sm" variant="danger">Archiver</Btn>
          </div>
        </Card>
      )}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B10 — Publier contenu (annonce ou ressource)
══════════════════════════════════════════════════════════════ */
export function PublishContentScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [type, setType] = useState<'annonce'|'ressource'>('annonce')
  const [form, setForm] = useState({ title: '', body: '', chapter: '', pinned: false })
  const [resType, setResType] = useState('file')
  const [done, setDone] = useState(false)

  function set(k: keyof typeof form) { return (v: string | boolean) => setForm(f => ({ ...f, [k]: v })) }

  if (done) return (
    <div className="max-w-md mx-auto px-5 py-10 text-center">
      <Card className="p-8">
        <div className="w-14 h-14 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-4"><Icons.Check/></div>
        <h2 className="font-display text-xl font-semibold mb-2">Publié avec succès</h2>
        <p className="text-sm text-[var(--muted-foreground)] mb-5">{type === 'annonce' ? 'L\'annonce a été diffusée à tous les membres.' : 'La ressource est disponible dans la classe.'}</p>
        <Btn full onClick={() => onNav('manage-class')}>Retour à la classe</Btn>
      </Card>
    </div>
  )

  return (
    <div className="max-w-xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Publier du contenu" back={() => onNav('manage-class')}/>

      {/* Type selector */}
      <div className="flex gap-2 mb-5">
        {(['annonce', 'ressource'] as const).map(t => (
          <button key={t} onClick={() => setType(t)}
            className={`flex-1 py-2.5 text-sm font-medium rounded-[var(--radius)] border transition-all capitalize ${type===t ? 'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)]' : 'border-[var(--border)] text-[var(--muted-foreground)] hover:border-[var(--primary)]/40'}`}>
            {t === 'annonce' ? 'Annonce' : 'Ressource'}
          </button>
        ))}
      </div>

      <Card className="p-6 space-y-4">
        <Select label="Classe" value="dev-web" onChange={()=>{}} options={[
          { value:'dev-web', label:'Développement Web' },
          { value:'algo', label:'Algorithmique' },
        ]}/>
        <Input label="Titre" placeholder={type==='annonce'?'ex. Rappel : examen final':'ex. Cours 05 — Vue.js'} value={form.title} onChange={set('title') as (v:string)=>void}/>

        {type === 'annonce' && (
          <>
            <Textarea label="Contenu" placeholder="Rédigez votre annonce ici…" value={form.body} onChange={set('body') as (v:string)=>void} rows={5}/>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium">Épingler l'annonce</p>
                <p className="text-xs text-[var(--muted-foreground)]">Apparaît en haut de la liste.</p>
              </div>
              <Toggle checked={form.pinned} onChange={v => set('pinned')(v)}/>
            </div>
          </>
        )}

        {type === 'ressource' && (
          <>
            <Input label="Chapitre" placeholder="ex. Chapitre 5" value={form.chapter} onChange={set('chapter') as (v:string)=>void}/>
            <Select label="Type" value={resType} onChange={setResType} options={[
              { value:'file', label:'Fichier (PDF, PPTX…)' },
              { value:'link', label:'Lien externe' },
            ]}/>
            {resType === 'file' ? (
              <div className="border-2 border-dashed border-[var(--border)] rounded-[var(--radius)] p-8 flex flex-col items-center gap-2 hover:border-[var(--primary)]/40 transition-colors cursor-pointer">
                <Icons.Upload/>
                <p className="text-sm font-medium">Glissez un fichier ici</p>
                <p className="text-xs text-[var(--muted-foreground)]">PDF, PPTX, DOCX — max 20 Mo</p>
                <Btn size="sm" variant="secondary">Parcourir</Btn>
              </div>
            ) : (
              <Input label="URL" placeholder="https://…"/>
            )}
          </>
        )}

        <Btn full onClick={() => setDone(true)} disabled={!form.title}>
          Publier
        </Btn>
      </Card>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B11 — Créer un quiz manuellement
══════════════════════════════════════════════════════════════ */
export function CreateQuizScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [step, setStep] = useState<'settings'|'questions'|'preview'>('settings')
  const [settings, setSettings] = useState({ title: '', time: '20', attempts: '2', shuffle: true, showAnswers: true })
  const [questions, setQuestions] = useState([
    { id: 1, statement: 'Quelle balise HTML définit un paragraphe ?', options: ['<p>', '<div>', '<span>', '<section>'], correct: 0 },
  ])

  function addQuestion() {
    setQuestions(qs => [...qs, { id: Date.now(), statement: '', options: ['', '', '', ''], correct: 0 }])
  }

  function setS(k: keyof typeof settings) { return (v: string|boolean) => setSettings(s => ({ ...s, [k]: v })) }

  return (
    <div className="max-w-3xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Créer un quiz" back={() => onNav('manage-class')}
        actions={
          <div className="flex items-center gap-2">
            {step === 'questions' && <Btn size="sm" variant="secondary" onClick={() => setStep('settings')}>← Paramètres</Btn>}
            {step === 'settings' && <Btn onClick={() => setStep('questions')}>Questions →</Btn>}
            {step === 'questions' && <Btn variant="accent" onClick={() => { onNav('manage-class') }}>Publier</Btn>}
          </div>
        }
      />

      {/* Step indicator */}
      <div className="flex items-center gap-2 mb-6">
        {(['settings','questions','preview'] as const).map((s, i) => {
          const labels = ['Paramètres','Questions','Aperçu']
          const active = step === s
          const done = ['settings','questions'].indexOf(step) > i
          return (
            <div key={s} className="flex items-center gap-2">
              <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold ${active ? 'bg-[var(--primary)] text-white' : done ? 'bg-green-500 text-white' : 'bg-[var(--muted)] text-[var(--muted-foreground)]'}`}>
                {done ? '✓' : i+1}
              </div>
              <span className={`text-sm ${active ? 'font-medium' : 'text-[var(--muted-foreground)]'}`}>{labels[i]}</span>
              {i < 2 && <div className="w-8 h-px bg-[var(--border)]"/>}
            </div>
          )
        })}
      </div>

      {step === 'settings' && (
        <Card className="p-6 space-y-4">
          <Select label="Classe" value="dev-web" onChange={()=>{}} options={[
            { value:'dev-web', label:'Développement Web' },
            { value:'algo', label:'Algorithmique' },
          ]}/>
          <Input label="Titre du quiz" placeholder="ex. Quiz — HTML/CSS Avancé" value={settings.title} onChange={setS('title') as (v:string)=>void}/>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Durée (minutes)" type="number" placeholder="20" value={settings.time} onChange={setS('time') as (v:string)=>void}/>
            <Input label="Tentatives max" type="number" placeholder="2" value={settings.attempts} onChange={setS('attempts') as (v:string)=>void}/>
          </div>
          <div className="border-t border-[var(--border)] pt-4 space-y-3">
            <div className="flex items-center justify-between">
              <div><p className="text-sm font-medium">Mélanger les questions</p><p className="text-xs text-[var(--muted-foreground)]">Ordre aléatoire pour chaque tentative.</p></div>
              <Toggle checked={settings.shuffle} onChange={v => setS('shuffle')(v)}/>
            </div>
            <div className="flex items-center justify-between">
              <div><p className="text-sm font-medium">Afficher les corrections</p><p className="text-xs text-[var(--muted-foreground)]">Visible après soumission.</p></div>
              <Toggle checked={settings.showAnswers} onChange={v => setS('showAnswers')(v)}/>
            </div>
          </div>
        </Card>
      )}

      {step === 'questions' && (
        <div className="space-y-4">
          {questions.map((q, qi) => (
            <Card key={q.id} className="p-5">
              <div className="flex items-center justify-between mb-3">
                <span className="text-xs font-medium text-[var(--muted-foreground)]">Question {qi + 1}</span>
                <button onClick={() => setQuestions(qs => qs.filter(x => x.id !== q.id))} className="text-[var(--muted-foreground)] hover:text-red-500"><Icons.Trash/></button>
              </div>
              <Textarea placeholder="Énoncé de la question…" value={q.statement}
                onChange={v => setQuestions(qs => qs.map(x => x.id===q.id ? {...x, statement:v} : x))} rows={2}/>
              <p className="text-xs font-medium mt-3 mb-2 text-[var(--muted-foreground)]">Options (cliquez sur ● pour marquer la bonne réponse)</p>
              {q.options.map((opt, oi) => (
                <div key={oi} className="flex items-center gap-2 mb-2">
                  <button onClick={() => setQuestions(qs => qs.map(x => x.id===q.id ? {...x, correct:oi} : x))}
                    className={`w-5 h-5 rounded-full border-2 shrink-0 transition-all ${q.correct===oi ? 'border-[var(--primary)] bg-[var(--primary)]' : 'border-[var(--border)]'}`}/>
                  <input value={opt} onChange={e => {
                    const opts = [...q.options]; opts[oi] = e.target.value
                    setQuestions(qs => qs.map(x => x.id===q.id ? {...x, options:opts} : x))
                  }} placeholder={`Option ${oi+1}`}
                    className="flex-1 px-3 py-1.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"/>
                </div>
              ))}
            </Card>
          ))}
          <button onClick={addQuestion}
            className="w-full py-3 border-2 border-dashed border-[var(--border)] rounded-[var(--radius)] text-sm text-[var(--muted-foreground)] hover:border-[var(--primary)]/40 hover:text-[var(--primary)] transition-all flex items-center justify-center gap-2">
            <Icons.Plus/> Ajouter une question
          </button>
        </div>
      )}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B12 — Générer un quiz par IA
══════════════════════════════════════════════════════════════ */
export function AiQuizScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [step, setStep] = useState<'upload'|'generating'|'review'>('upload')
  const [dragOver, setDragOver] = useState(false)

  function startGenerate() {
    setStep('generating')
    setTimeout(() => setStep('review'), 2800)
  }

  const generatedQuestions = [
    { statement: 'Quel est le modèle OSI et combien de couches contient-il ?', correct: '7 couches' },
    { statement: 'Quelle est la différence entre TCP et UDP ?', correct: 'TCP est orienté connexion, UDP non.' },
    { statement: 'Qu\'est-ce qu\'une adresse IP et comment est-elle structurée ?', correct: 'Identifiant numérique en 4 octets.' },
  ]

  return (
    <div className="max-w-2xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Générer un quiz par IA" subtitle="Téléversez un PDF de cours et l'IA génère un quiz en brouillon." back={() => onNav('manage-class')}/>

      {/* Progress steps */}
      <div className="flex items-center gap-0 mb-7">
        {[{id:'upload',label:'Téléversement'},{id:'generating',label:'Génération'},{id:'review',label:'Relecture'}].map((s, i, arr) => {
          const idx = ['upload','generating','review'].indexOf(step)
          const done = ['upload','generating','review'].indexOf(step) > i
          const active = step === s.id
          return (
            <div key={s.id} className="flex items-center">
              <div className="flex flex-col items-center">
                <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold ${active?'bg-[var(--accent)] text-white':done?'bg-green-500 text-white':'bg-[var(--muted)] text-[var(--muted-foreground)]'}`}>
                  {done ? '✓' : i+1}
                </div>
                <span className={`text-[10px] mt-1 ${active?'font-medium':'text-[var(--muted-foreground)]'}`}>{s.label}</span>
              </div>
              {i < arr.length-1 && <div className="w-16 h-px bg-[var(--border)] mb-4 mx-1"/>}
            </div>
          )
        })}
      </div>

      {step === 'upload' && (
        <div className="space-y-4">
          <Card className="p-6 space-y-4">
            <Select label="Classe" value="dev-web" onChange={()=>{}} options={[
              {value:'dev-web',label:'Développement Web'},{value:'net',label:'Réseaux informatiques'},
            ]}/>
            <div
              onDragOver={e=>{e.preventDefault();setDragOver(true)}}
              onDragLeave={()=>setDragOver(false)}
              onDrop={e=>{e.preventDefault();setDragOver(false)}}
              className={`border-2 border-dashed rounded-[var(--radius)] p-10 text-center cursor-pointer transition-all ${dragOver?'border-[var(--accent)] bg-orange-50':'border-[var(--border)] hover:border-[var(--primary)]/40'}`}>
              <Icons.Upload/>
              <p className="mt-3 font-medium text-sm">Glissez votre PDF de cours ici</p>
              <p className="text-xs text-[var(--muted-foreground)] mt-1">ou cliquez pour parcourir — max 20 Mo</p>
              <Btn size="sm" variant="secondary" className="mt-4">Sélectionner un fichier</Btn>
            </div>
            <div className="p-3 rounded-lg bg-[var(--secondary)] flex items-start gap-2">
              <Icons.Cpu/>
              <p className="text-xs text-[var(--muted-foreground)]">L'IA analyse le contenu du cours et génère automatiquement des questions QCM. Vous pourrez relire et corriger avant publication.</p>
            </div>
          </Card>
          <Btn full onClick={startGenerate}>Lancer la génération</Btn>
        </div>
      )}

      {step === 'generating' && (
        <Card className="p-10 text-center">
          <div className="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-5" style={{ background:'rgba(232,130,12,0.1)' }}>
            <div className="w-8 h-8 border-3 border-[var(--accent)] border-t-transparent rounded-full animate-spin" style={{ borderWidth:3 }}/>
          </div>
          <h3 className="font-display font-semibold text-lg mb-2">Génération en cours…</h3>
          <p className="text-sm text-[var(--muted-foreground)] mb-4">L'IA analyse votre PDF et formule les questions. Cela prend environ 30 secondes.</p>
          <div className="h-1.5 rounded-full bg-[var(--muted)] overflow-hidden">
            <div className="h-full rounded-full bg-[var(--accent)] animate-pulse" style={{ width:'65%' }}/>
          </div>
        </Card>
      )}

      {step === 'review' && (
        <div className="space-y-4">
          <Alert message="Quiz brouillon généré — 8 questions. Relisez et corrigez avant publication." type="success"/>
          {generatedQuestions.map((q, i) => (
            <Card key={i} className="p-5">
              <p className="text-xs text-[var(--muted-foreground)] mb-1">Question {i+1}</p>
              <p className="font-medium text-sm mb-3">{q.statement}</p>
              <p className="text-xs text-green-600 font-medium">✓ Réponse : {q.correct}</p>
              <button className="mt-2 text-xs text-[var(--primary)] hover:underline flex items-center gap-1"><Icons.Pencil/>Modifier</button>
            </Card>
          ))}
          <p className="text-xs text-[var(--muted-foreground)] text-center">… et 5 autres questions</p>
          <div className="flex gap-2">
            <Btn full variant="secondary" onClick={() => onNav('create-quiz')}>Ouvrir dans l'éditeur</Btn>
            <Btn full variant="accent" onClick={() => onNav('manage-class')}>Publier le quiz</Btn>
          </div>
        </div>
      )}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B13 — Corriger les devoirs
══════════════════════════════════════════════════════════════ */
export function GradeAssignmentsScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [submissions, setSubmissions] = useState([
    { id: 1, name: 'Yassine Benali', file: 'tp2-yassine.pdf', submittedAt: '14 oct. 2026 — 22h14', late: false, grade: '', feedback: '' },
    { id: 2, name: 'Sara Idrissi', file: 'tp2-sara.pdf', submittedAt: '14 oct. 2026 — 18h05', late: false, grade: '16', feedback: 'Très bon travail.' },
    { id: 3, name: 'Karim Tahir', file: 'tp2-karim.pdf', submittedAt: '16 oct. 2026 — 10h30', late: true, grade: '', feedback: '' },
  ])
  const [selected, setSelected] = useState<number|null>(null)

  const sub = submissions.find(s => s.id === selected)

  function save() {
    setSelected(null)
  }

  return (
    <div className="max-w-4xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Corriger les devoirs" subtitle="TP2 — Mise en page responsive · Date limite : 15 oct. 2026" back={() => onNav('manage-class')}/>

      <div className="grid md:grid-cols-5 gap-5">
        {/* Submissions list */}
        <div className="md:col-span-2 space-y-2">
          <div className="flex items-center justify-between mb-1">
            <h2 className="text-sm font-semibold">Copies reçues</h2>
            <Badge label={`${submissions.length}/28`} color="default"/>
          </div>
          {submissions.map(s => (
            <Card key={s.id} className={`px-4 py-3 cursor-pointer transition-all ${selected===s.id?'border-[var(--primary)] bg-[var(--secondary)]':''}`}
              onClick={() => setSelected(s.id)}>
              <div className="flex items-center gap-2">
                <Avatar name={s.name} size="sm"/>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm truncate">{s.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{s.submittedAt}</p>
                </div>
                {s.late && <Badge label="Retard" color="red"/>}
                {s.grade && <Badge label={`${s.grade}/20`} color="green"/>}
              </div>
            </Card>
          ))}
        </div>

        {/* Grading panel */}
        <div className="md:col-span-3">
          {!sub ? (
            <Card className="p-8 text-center">
              <p className="text-sm text-[var(--muted-foreground)]">Sélectionnez une copie pour la corriger.</p>
            </Card>
          ) : (
            <Card className="p-5">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <p className="font-medium">{sub.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{sub.submittedAt} {sub.late && '· En retard'}</p>
                </div>
                {sub.late && <Badge label="En retard" color="red"/>}
              </div>

              {/* File preview stub */}
              <div className="rounded-[var(--radius)] bg-[var(--muted)] h-40 flex items-center justify-center mb-4 cursor-pointer hover:bg-[var(--border)] transition-colors">
                <div className="text-center">
                  <Icons.Book/>
                  <p className="text-xs text-[var(--muted-foreground)] mt-2">{sub.file}</p>
                  <p className="text-xs text-[var(--primary)] mt-1 hover:underline">Ouvrir le fichier</p>
                </div>
              </div>

              <div className="space-y-3">
                <Input label="Note /20" type="number" placeholder="ex. 15" value={sub.grade}
                  onChange={v => setSubmissions(ss => ss.map(s => s.id===selected?{...s,grade:v}:s))}/>
                <Textarea label="Commentaire (optionnel)" placeholder="Retour à l'étudiant…" value={sub.feedback}
                  onChange={v => setSubmissions(ss => ss.map(s => s.id===selected?{...s,feedback:v}:s))} rows={3}/>
                <div className="flex gap-2 pt-1">
                  <Btn full variant="secondary" onClick={() => setSelected(null)}>Annuler</Btn>
                  <Btn full variant="success" onClick={save}><Icons.Check/>Enregistrer</Btn>
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B14 — Progression de la classe (enseignant)
══════════════════════════════════════════════════════════════ */
export function ClassProgressScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [cls, setCls] = useState('dev-web')

  const students = [
    { name: 'Sara Idrissi', quiz: 88, homework: 92, avg: 90 },
    { name: 'Yassine Benali', quiz: 74, homework: 81, avg: 77 },
    { name: 'Karim Tahir', quiz: 65, homework: 55, avg: 60 },
    { name: 'Nadia Ouali', quiz: 79, homework: 75, avg: 77 },
    { name: 'Amine Berrada', quiz: 91, homework: 88, avg: 89 },
    { name: 'Fatima Zahra H.', quiz: 50, homework: 48, avg: 49 },
  ]

  const quizStats = [
    { title: 'Quiz HTML/CSS Avancé', avg: 74, attempts: 18, max: 100, min: 35 },
    { title: 'Quiz Introduction HTML', avg: 81, attempts: 25, max: 100, min: 50 },
  ]

  return (
    <div className="px-5 md:px-8 py-6 max-w-4xl mx-auto">
      <PageHeader title="Progression de la classe" actions={
        <Select value={cls} onChange={setCls} options={[
          { value:'dev-web', label:'Développement Web' },
          { value:'algo', label:'Algorithmique' },
          { value:'net', label:'Réseaux informatiques' },
        ]}/>
      }/>

      {/* Overview stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
        <StatTile label="Moyenne quiz" value="74%" color="var(--primary)"/>
        <StatTile label="Taux de remise" value="86%" color="#16A34A"/>
        <StatTile label="Étudiants à risque" value="2" color="var(--danger)"/>
        <StatTile label="Excellent (≥85%)" value="3" color="#7C3AED"/>
      </div>

      <div className="grid md:grid-cols-5 gap-5">
        {/* Students table */}
        <div className="md:col-span-3">
          <h2 className="font-semibold text-sm mb-3">Suivi individuel</h2>
          <Card>
            <div className="px-4 py-2.5 border-b border-[var(--border)] grid grid-cols-4 gap-2">
              {['Étudiant','Quiz','Devoirs','Moyenne'].map(h => (
                <p key={h} className="text-xs font-medium text-[var(--muted-foreground)]">{h}</p>
              ))}
            </div>
            {students.map(s => {
              const risk = s.avg < 55
              return (
                <div key={s.name} className={`px-4 py-3 border-b border-[var(--border)] last:border-0 grid grid-cols-4 gap-2 items-center ${risk?'bg-red-50':''}`}>
                  <div className="flex items-center gap-2">
                    <Avatar name={s.name} size="sm" color={risk?'#FEE2E2':'var(--secondary)'} textColor={risk?'#DC2626':'var(--primary)'}/>
                    <span className="text-xs font-medium truncate">{s.name.split(' ')[0]}</span>
                  </div>
                  <span className={`text-xs font-medium ${s.quiz>=75?'text-green-600':s.quiz>=60?'text-[var(--accent)]':'text-red-600'}`}>{s.quiz}%</span>
                  <span className={`text-xs font-medium ${s.homework>=75?'text-green-600':s.homework>=60?'text-[var(--accent)]':'text-red-600'}`}>{s.homework}%</span>
                  <span className={`text-xs font-bold ${s.avg>=75?'text-green-600':s.avg>=60?'text-[var(--accent)]':'text-red-600'}`}>{s.avg}%</span>
                </div>
              )
            })}
          </Card>
        </div>

        {/* Quiz breakdown */}
        <div className="md:col-span-2 space-y-3">
          <h2 className="font-semibold text-sm mb-3">Résultats par quiz</h2>
          {quizStats.map(q => (
            <Card key={q.title} className="p-4">
              <p className="font-medium text-sm mb-3">{q.title}</p>
              <div className="space-y-2">
                {[{label:'Moyenne',v:q.avg},{label:'Maximum',v:q.max},{label:'Minimum',v:q.min}].map(r => (
                  <div key={r.label} className="flex items-center gap-3">
                    <span className="text-xs w-16 text-[var(--muted-foreground)]">{r.label}</span>
                    <div className="flex-1"><ProgressBar value={r.v} color={r.label==='Minimum'?'#DC2626':r.label==='Maximum'?'#16A34A':'var(--primary)'}/></div>
                    <span className="text-xs font-medium w-8 text-right">{r.v}%</span>
                  </div>
                ))}
              </div>
              <p className="text-xs text-[var(--muted-foreground)] mt-2">{q.attempts} tentatives</p>
            </Card>
          ))}
        </div>
      </div>
    </div>
  )
}
