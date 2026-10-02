import { useState } from 'react'
import { Btn, Badge, Card, Input, Textarea, StatTile, ProgressBar, Avatar, Alert, PageHeader, Tabs, Toggle, Icons } from '../components/UI'
import type { Screen } from '../components/UI'

/* ══════════════════════════════════════════════════════════════
   B2 — Tableau de bord étudiant
══════════════════════════════════════════════════════════════ */
export function DashboardScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const stats = [
    { label: 'Classes actives', value: '3', color: 'var(--primary)' },
    { label: 'Quiz complétés', value: '12', color: '#16A34A' },
    { label: 'Moyenne générale', value: '78%', color: 'var(--accent)' },
    { label: 'Devoirs en attente', value: '2', color: '#7C3AED' },
  ]
  const classes = [
    { name: 'Développement Web', teacher: 'M. Alaoui', subject: 'TDI', members: 28, progress: 65 },
    { name: 'Algorithmique', teacher: 'Mme. Zahra', subject: 'TDI', members: 24, progress: 48 },
    { name: 'Réseaux informatiques', teacher: 'M. Bakkali', subject: 'IR', members: 31, progress: 72 },
  ]
  const upcoming = [
    { title: 'Quiz — HTML/CSS Avancé', class: 'Développement Web', date: '2 oct.', questions: 15 },
    { title: 'Quiz — Tri et Recherche', class: 'Algorithmique', date: '5 oct.', questions: 10 },
  ]

  return (
    <div className="px-5 md:px-8 py-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-7">
        <div>
          <h1 className="font-display text-2xl font-semibold">Bonjour, Yassine 👋</h1>
          <p className="text-sm text-[var(--muted-foreground)] mt-0.5">Mercredi 30 septembre 2026</p>
        </div>
        <button className="hidden md:flex p-2.5 rounded-[var(--radius)] hover:bg-[var(--muted)] relative">
          <Icons.Bell/>
          <span className="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-[var(--accent)]"/>
        </button>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
        {stats.map(s => <StatTile key={s.label} label={s.label} value={s.value} color={s.color}/>)}
      </div>

      <div className="grid md:grid-cols-5 gap-5">
        <div className="md:col-span-3 space-y-2.5">
          <div className="flex items-center justify-between mb-1">
            <h2 className="font-semibold text-sm">Mes classes</h2>
            <button onClick={() => onNav('join')} className="text-xs text-[var(--primary)] font-medium hover:underline flex items-center gap-1">
              <Icons.Plus/>Rejoindre
            </button>
          </div>
          {classes.map(cls => (
            <Card key={cls.name} className="p-4" onClick={() => onNav('class')}>
              <div className="flex items-start justify-between mb-3">
                <div>
                  <p className="font-medium text-sm">{cls.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{cls.teacher} · {cls.members} étudiants</p>
                </div>
                <Badge label={cls.subject} color="blue"/>
              </div>
              <div className="flex items-center gap-2">
                <div className="flex-1"><ProgressBar value={cls.progress}/></div>
                <span className="text-xs text-[var(--muted-foreground)]">{cls.progress}%</span>
              </div>
            </Card>
          ))}
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <h2 className="font-semibold text-sm mb-1">Quiz à venir</h2>
          {upcoming.map(q => (
            <Card key={q.title} className="p-4">
              <p className="font-medium text-sm leading-tight">{q.title}</p>
              <p className="text-xs text-[var(--muted-foreground)] mt-1">{q.class}</p>
              <div className="flex items-center justify-between mt-3">
                <div className="flex items-center gap-1 text-xs text-[var(--muted-foreground)]">
                  <Icons.Clock/>{q.date}
                </div>
                <Badge label={`${q.questions} questions`}/>
              </div>
            </Card>
          ))}

          <Card className="p-4">
            <div className="flex items-center justify-between mb-3">
              <p className="font-medium text-sm">Progression globale</p>
            </div>
            {[{label:'Quiz',pct:78},{label:'Devoirs',pct:50},{label:'Présence',pct:92}].map(r => (
              <div key={r.label} className="flex items-center gap-3 mb-2 last:mb-0">
                <span className="text-xs w-14 text-[var(--muted-foreground)]">{r.label}</span>
                <div className="flex-1"><ProgressBar value={r.pct} color={r.pct>80?'#16A34A':r.pct>60?'var(--accent)':'#DC2626'}/></div>
                <span className="text-xs font-medium w-8 text-right">{r.pct}%</span>
              </div>
            ))}
          </Card>
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B3 — Page de classe (étudiant)
══════════════════════════════════════════════════════════════ */
export function ClassScreen({ onNav }: { onNav: (s: Screen) => void }) {
  const [tab, setTab] = useState('annonces')
  const tabs = [{id:'annonces',label:'Annonces'},{id:'ressources',label:'Ressources'},{id:'quiz',label:'Quiz'},{id:'membres',label:'Membres'}]

  const annonces = [
    { title: 'TP3 — Mise en ligne des consignes', body: 'Les consignes du troisième travail pratique sont disponibles dans l\'onglet Ressources. Date limite : 10 octobre 2026.', date: '28 sept.', pinned: true },
    { title: 'Rappel : quiz de mi-semestre', body: 'Le quiz de mi-semestre aura lieu le 5 octobre. Révisez les chapitres 1 à 4.', date: '25 sept.', pinned: false },
  ]
  const ressources = [
    { title: 'Cours 04 — JavaScript ES6', type: 'PDF', chapter: 'Chapitre 4' },
    { title: 'Cours 03 — CSS Flexbox & Grid', type: 'PDF', chapter: 'Chapitre 3' },
    { title: 'MDN Web Docs', type: 'Lien', chapter: 'Référence' },
    { title: 'Cours 02 — HTML Sémantique', type: 'PDF', chapter: 'Chapitre 2' },
  ]
  const quizzes = [
    { title: 'Quiz — HTML/CSS Avancé', status: 'Disponible', questions: 15, time: '20 min', attempts: '0/2' },
    { title: 'Quiz — Introduction HTML', status: 'Terminé', questions: 10, time: '15 min', attempts: '1/2', score: '80%' },
  ]
  const membres = [
    { name: 'M. Alaoui', role: 'Enseignant' },
    { name: 'Yassine Benali', role: 'Étudiant' },
    { name: 'Sara Idrissi', role: 'Étudiant' },
    { name: 'Karim Tahir', role: 'Étudiant' },
    { name: 'Nadia Ouali', role: 'Étudiant' },
  ]

  return (
    <div className="max-w-4xl mx-auto px-5 md:px-8 py-6">
      <div className="mb-5">
        <button onClick={() => onNav('dashboard')} className="text-xs text-[var(--muted-foreground)] mb-2 hover:text-[var(--foreground)] flex items-center gap-1">← Mes classes</button>
        <div className="flex items-start justify-between">
          <div>
            <h1 className="font-display text-2xl font-semibold">Développement Web</h1>
            <p className="text-sm text-[var(--muted-foreground)] mt-0.5">M. Alaoui · TDI 2025–2026 · 28 étudiants</p>
          </div>
          <Badge label="Active" color="green"/>
        </div>
      </div>

      <div className="mb-5"><Tabs tabs={tabs} active={tab} onChange={setTab}/></div>

      {tab === 'annonces' && (
        <div className="space-y-3">
          {annonces.map(a => (
            <Card key={a.title} className="p-5">
              <div className="flex items-center gap-2 mb-1">
                {a.pinned && <Badge label="Épinglé" color="orange"/>}
                <h3 className="font-medium">{a.title}</h3>
              </div>
              <p className="text-sm text-[var(--muted-foreground)] leading-relaxed">{a.body}</p>
              <p className="text-xs text-[var(--muted-foreground)] mt-3">M. Alaoui · {a.date}</p>
            </Card>
          ))}
        </div>
      )}

      {tab === 'ressources' && (
        <div className="space-y-2">
          {ressources.map(r => (
            <Card key={r.title} className="p-4 flex items-center gap-4" onClick={()=>{}}>
              <div className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                style={{ background:r.type==='PDF'?'#FEE2E2':'#DBEAFE' }}>
                <Icons.Book/>
              </div>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm truncate">{r.title}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{r.chapter}</p>
              </div>
              <Badge label={r.type} color={r.type==='PDF'?'red':'blue'}/>
              <Icons.External/>
            </Card>
          ))}
        </div>
      )}

      {tab === 'quiz' && (
        <div className="space-y-3">
          {quizzes.map(q => (
            <Card key={q.title} className="p-5">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                  <h3 className="font-medium">{q.title}</h3>
                  <div className="flex items-center gap-3 mt-1">
                    <span className="text-xs text-[var(--muted-foreground)]">{q.questions} questions</span>
                    <span className="text-xs text-[var(--muted-foreground)]">{q.time}</span>
                    <span className="text-xs text-[var(--muted-foreground)]">Tentatives : {q.attempts}</span>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {q.score && <Badge label={q.score} color="green"/>}
                  <Badge label={q.status} color={q.status==='Disponible'?'blue':'default'}/>
                  {q.status === 'Disponible' && <Btn onClick={() => onNav('quiz')}>Commencer</Btn>}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {tab === 'membres' && (
        <div className="space-y-2">
          {membres.map(m => (
            <Card key={m.name} className="px-4 py-3 flex items-center gap-3">
              <Avatar name={m.name} color={m.role==='Enseignant'?'var(--accent)':undefined} textColor={m.role==='Enseignant'?'white':undefined}/>
              <p className="font-medium text-sm flex-1">{m.name}</p>
              <Badge label={m.role} color={m.role==='Enseignant'?'orange':'default'}/>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B4 — Rejoindre une classe
══════════════════════════════════════════════════════════════ */
export function JoinScreen() {
  const [code, setCode] = useState('')
  const [state, setState] = useState<'idle'|'loading'|'pending'|'error'>('idle')

  function submit() {
    setState('loading')
    setTimeout(() => {
      if (code.trim().toUpperCase() === 'INVALID') setState('error')
      else setState('pending')
    }, 900)
  }

  return (
    <div className="max-w-lg mx-auto px-5 md:px-8 py-10">
      <PageHeader title="Rejoindre une classe" subtitle="Saisissez le code fourni par votre enseignant."/>

      {state !== 'pending' && (
        <Card className="p-6 space-y-4">
          <Input label="Code de la classe" placeholder="ex. TDI-202" value={code} onChange={v => { setCode(v); if (state !== 'idle') setState('idle') }}/>
          {state === 'error' && <Alert message="Code invalide ou désactivé. Vérifiez avec votre enseignant." type="error"/>}
          <Btn full onClick={submit} disabled={state==='loading'||!code.trim()}>
            {state === 'loading' ? 'Vérification…' : 'Envoyer la demande'}
          </Btn>
        </Card>
      )}

      {state === 'pending' && (
        <Card className="p-6 text-center space-y-3">
          <div className="w-14 h-14 rounded-full bg-orange-50 flex items-center justify-center mx-auto">
            <Icons.Clock/>
          </div>
          <h3 className="font-display font-semibold text-lg">Demande envoyée</h3>
          <p className="text-sm text-[var(--muted-foreground)]">Votre demande pour la classe <strong>{code}</strong> est en attente de validation par l'enseignant.</p>
          <Badge label="En attente d'approbation" color="orange"/>
          <div className="pt-2">
            <button onClick={() => { setState('idle'); setCode('') }} className="text-xs text-[var(--muted-foreground)] hover:underline">Rejoindre une autre classe</button>
          </div>
        </Card>
      )}

      <div className="mt-5 p-4 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--card)] space-y-2">
        <p className="text-xs font-medium">Comment ça fonctionne ?</p>
        {['Votre enseignant partage un code unique.','Saisissez le code et envoyez votre demande.','L\'enseignant approuve ou refuse votre demande.','Une fois accepté, la classe apparaît dans votre tableau de bord.'].map((s, i) => (
          <div key={i} className="flex items-start gap-2">
            <span className="w-4 h-4 rounded-full text-[10px] font-bold flex items-center justify-center shrink-0 mt-0.5" style={{ background:'var(--primary)', color:'white' }}>{i+1}</span>
            <p className="text-xs text-[var(--muted-foreground)]">{s}</p>
          </div>
        ))}
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Quiz screens — B6 (passage) + résultat
══════════════════════════════════════════════════════════════ */
const QUESTIONS = [
  { id:1, statement:'Quelle propriété CSS crée une disposition en colonnes flexibles ?', options:['display: grid','display: flex','display: inline-block','display: table'], correct:1 },
  { id:2, statement:'Quel attribut HTML spécifie l\'URL d\'un lien ?', options:['href','src','target','rel'], correct:0 },
  { id:3, statement:'En JavaScript, quelle méthode parcourt un tableau et retourne un nouveau tableau ?', options:['forEach()','map()','filter()','reduce()'], correct:1 },
  { id:4, statement:'Quelle valeur de display permet à un élément d\'occuper toute la largeur disponible ?', options:['inline','block','inline-block','flex'], correct:1 },
  { id:5, statement:'Quelle pseudo-classe CSS cible un élément au survol de la souris ?', options:[':focus',':hover',':active',':visited'], correct:1 },
]

export function QuizScreen({ onFinish }: { onFinish: ()=>void }) {
  const [current, setCurrent] = useState(0)
  const [answers, setAnswers] = useState<Record<number,number>>({})
  const [seconds] = useState(20 * 60)

  const q = QUESTIONS[current]
  const selected = answers[current]
  const mm = String(Math.floor(seconds/60)).padStart(2,'0')
  const ss = String(seconds%60).padStart(2,'0')

  function pick(i: number) { setAnswers(a => ({ ...a, [current]: i })) }

  return (
    <div className="max-w-2xl mx-auto px-5 md:px-8 py-6">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="font-display text-lg font-semibold">Quiz — HTML/CSS Avancé</h1>
          <p className="text-xs text-[var(--muted-foreground)] mt-0.5">Développement Web · {QUESTIONS.length} questions</p>
        </div>
        <div className="flex items-center gap-2 px-3 py-1.5 rounded-full border border-[var(--border)] bg-white text-sm font-medium" style={{ color: seconds<300?'var(--danger)':'var(--foreground)' }}>
          <Icons.Clock/>{mm}:{ss}
        </div>
      </div>

      <div className="mb-5">
        <div className="flex justify-between text-xs text-[var(--muted-foreground)] mb-1.5">
          <span>Question {current+1} sur {QUESTIONS.length}</span>
          <span>{Object.keys(answers).length} répondue{Object.keys(answers).length!==1?'s':''}</span>
        </div>
        <ProgressBar value={((current+1)/QUESTIONS.length)*100}/>
      </div>

      <Card className="p-6 mb-4">
        <p className="font-medium leading-relaxed mb-5">{q.statement}</p>
        <div className="space-y-2.5">
          {q.options.map((opt, i) => {
            const sel = selected === i
            return (
              <button key={i} onClick={() => pick(i)}
                className={`w-full text-left px-4 py-3 rounded-[var(--radius)] border text-sm transition-all ${sel?'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)] font-medium':'border-[var(--border)] bg-white hover:border-[var(--primary)]/40'}`}>
                <span className="flex items-center gap-3">
                  <span className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 ${sel?'border-[var(--primary)] bg-[var(--primary)]':'border-[var(--border)]'}`}>
                    {sel && <Icons.Check/>}
                  </span>
                  {opt}
                </span>
              </button>
            )
          })}
        </div>
      </Card>

      <div className="flex items-center justify-between">
        <Btn variant="secondary" onClick={() => setCurrent(c => Math.max(0,c-1))} disabled={current===0}>← Précédente</Btn>
        <div className="flex gap-1">
          {QUESTIONS.map((_, i) => (
            <button key={i} onClick={() => setCurrent(i)}
              className={`w-7 h-7 rounded-full text-xs font-medium transition-all ${i===current?'bg-[var(--primary)] text-white':answers[i]!==undefined?'bg-[var(--primary)]/40 text-white':'bg-[var(--muted)] text-[var(--muted-foreground)]'}`}>
              {i+1}
            </button>
          ))}
        </div>
        {current < QUESTIONS.length-1
          ? <Btn onClick={() => setCurrent(c => c+1)} disabled={selected===undefined}>Suivante →</Btn>
          : <Btn variant="accent" onClick={onFinish} disabled={Object.keys(answers).length<QUESTIONS.length}>Soumettre</Btn>
        }
      </div>
    </div>
  )
}

export function QuizResultScreen({ onBack }: { onBack: ()=>void }) {
  const score = 4; const total = QUESTIONS.length; const pct = Math.round((score/total)*100)
  const details = QUESTIONS.map((q, i) => ({ statement: q.statement, correct: q.options[q.correct], userAnswer: q.options[i%2===0?q.correct:(q.correct+1)%4], isCorrect: i%2===0 }))

  return (
    <div className="max-w-2xl mx-auto px-5 md:px-8 py-10">
      <Card className="p-8 text-center mb-5">
        <div className="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-5"
          style={{ background:pct>=70?'#DCFCE7':'#FEE2E2' }}>
          <span className="text-2xl font-display font-semibold" style={{ color:pct>=70?'#16A34A':'#DC2626' }}>{pct}%</span>
        </div>
        <h2 className="font-display text-xl font-semibold mb-1">Quiz terminé !</h2>
        <p className="text-sm text-[var(--muted-foreground)] mb-5">Vous avez obtenu <strong>{score}/{total}</strong> réponses correctes.</p>
        <div className="grid grid-cols-3 gap-3 mb-6">
          {[{label:'Score',val:`${pct}%`},{label:'Correctes',val:`${score}`},{label:'Incorrectes',val:`${total-score}`}].map(s => (
            <div key={s.label} className="p-3 rounded-lg bg-[var(--muted)]">
              <p className="text-lg font-semibold">{s.val}</p>
              <p className="text-xs text-[var(--muted-foreground)]">{s.label}</p>
            </div>
          ))}
        </div>
        <Btn full onClick={onBack}>Retour à la classe</Btn>
      </Card>

      {/* Corrections */}
      <h2 className="font-semibold text-sm mb-3">Correction détaillée</h2>
      <div className="space-y-3">
        {details.map((d, i) => (
          <Card key={i} className={`p-4 border-l-4 ${d.isCorrect?'border-l-green-500':'border-l-red-500'}`}>
            <p className="font-medium text-sm mb-2">{i+1}. {d.statement}</p>
            <p className="text-xs text-green-700 mb-1">✓ Bonne réponse : {d.correct}</p>
            {!d.isCorrect && <p className="text-xs text-red-600">✗ Votre réponse : {d.userAnswer}</p>}
          </Card>
        ))}
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Échéances
══════════════════════════════════════════════════════════════ */
export function DeadlinesScreen() {
  const deadlines = [
    { title: 'Quiz — HTML/CSS Avancé', class: 'Développement Web', due: '2 oct. 2026', type: 'quiz', urgent: true },
    { title: 'TP2 — Mise en page responsive', class: 'Développement Web', due: '15 oct. 2026', type: 'devoir', urgent: false },
    { title: 'Quiz — Tri et Recherche', class: 'Algorithmique', due: '5 oct. 2026', type: 'quiz', urgent: true },
    { title: 'TP1 — Configuration réseau', class: 'Réseaux informatiques', due: '20 oct. 2026', type: 'devoir', urgent: false },
    { title: 'Devoir maison — Algorithmes de graphes', class: 'Algorithmique', due: '25 oct. 2026', type: 'devoir', urgent: false },
  ]

  return (
    <div className="max-w-3xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Mes échéances" subtitle="Toutes vos tâches et quiz à venir, classés par date."/>

      <div className="space-y-2.5">
        {deadlines.map((d, i) => (
          <Card key={i} className={`p-4 flex items-center gap-4 ${d.urgent?'border-orange-200':''}`}>
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center shrink-0 ${d.type==='quiz'?'bg-blue-50':'bg-purple-50'}`}>
              {d.type === 'quiz' ? <Icons.Quiz/> : <Icons.Book/>}
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-medium text-sm">{d.title}</p>
              <p className="text-xs text-[var(--muted-foreground)]">{d.class}</p>
            </div>
            <div className="text-right shrink-0">
              <div className="flex items-center gap-1 text-xs text-[var(--muted-foreground)]">
                <Icons.Clock/>{d.due}
              </div>
              {d.urgent && <Badge label="Bientôt" color="orange"/>}
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Partenaires d'étude
══════════════════════════════════════════════════════════════ */
export function PartnersScreen() {
  const [optIn, setOptIn] = useState(true)
  const [skills, setSkills] = useState(['JavaScript', 'HTML/CSS'])
  const [availability, setAvailability] = useState('week-evenings')
  const [newSkill, setNewSkill] = useState('')

  const partners = [
    { name: 'Sara Idrissi', skills: ['JavaScript', 'React'], availability: 'Soirs en semaine', class: 'Développement Web', match: 92 },
    { name: 'Amine Berrada', skills: ['HTML/CSS', 'Python'], availability: 'Week-end', class: 'Développement Web', match: 78 },
    { name: 'Nadia Ouali', skills: ['CSS', 'Figma'], availability: 'Soirs en semaine', class: 'Développement Web', match: 71 },
  ]

  function addSkill() {
    if (newSkill.trim() && !skills.includes(newSkill.trim())) {
      setSkills(s => [...s, newSkill.trim()])
      setNewSkill('')
    }
  }

  return (
    <div className="max-w-4xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Partenaires d'étude" subtitle="Trouvez des camarades pour réviser et travailler ensemble."/>

      <div className="grid md:grid-cols-5 gap-5">
        {/* Profile setup */}
        <div className="md:col-span-2 space-y-3">
          <Card className="p-5 space-y-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="font-medium text-sm">Participer</p>
                <p className="text-xs text-[var(--muted-foreground)]">Rendre votre profil visible.</p>
              </div>
              <Toggle checked={optIn} onChange={setOptIn}/>
            </div>

            {optIn && (
              <>
                <div>
                  <p className="text-sm font-medium mb-2">Mes compétences</p>
                  <div className="flex flex-wrap gap-1 mb-2">
                    {skills.map(s => (
                      <span key={s} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-[var(--secondary)] text-[var(--primary)] font-medium">
                        {s}
                        <button onClick={() => setSkills(sk => sk.filter(x=>x!==s))} className="hover:text-red-500"><Icons.X/></button>
                      </span>
                    ))}
                  </div>
                  <div className="flex gap-2">
                    <input value={newSkill} onChange={e=>setNewSkill(e.target.value)} onKeyDown={e=>e.key==='Enter'&&addSkill()}
                      placeholder="Ajouter…" className="flex-1 px-3 py-1.5 text-xs border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"/>
                    <Btn size="sm" onClick={addSkill}>+</Btn>
                  </div>
                </div>

                <div>
                  <p className="text-sm font-medium mb-1">Disponibilité</p>
                  {[{v:'week-evenings',l:'Soirs en semaine'},{v:'weekends',l:'Week-end'},{v:'anytime',l:'Flexible'}].map(a => (
                    <label key={a.v} className="flex items-center gap-2 py-1 cursor-pointer">
                      <div onClick={() => setAvailability(a.v)}
                        className={`w-4 h-4 rounded-full border-2 ${availability===a.v?'border-[var(--primary)] bg-[var(--primary)]':'border-[var(--border)]'}`}/>
                      <span className="text-sm">{a.l}</span>
                    </label>
                  ))}
                </div>
              </>
            )}
          </Card>
        </div>

        {/* Partners list */}
        <div className="md:col-span-3 space-y-3">
          {!optIn && (
            <Alert message="Activez la participation pour voir et être visible par d'autres étudiants." type="info"/>
          )}
          {optIn && partners.map(p => (
            <Card key={p.name} className="p-4">
              <div className="flex items-start gap-3">
                <Avatar name={p.name} size="md"/>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between">
                    <p className="font-medium text-sm">{p.name}</p>
                    <Badge label={`${p.match}% compatibilité`} color="green"/>
                  </div>
                  <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{p.class} · {p.availability}</p>
                  <div className="flex flex-wrap gap-1 mt-2">
                    {p.skills.map(s => <Badge key={s} label={s} color="blue"/>)}
                  </div>
                </div>
              </div>
              <div className="flex gap-2 mt-3">
                <Btn size="sm" variant="secondary" full>Envoyer une demande</Btn>
              </div>
            </Card>
          ))}
        </div>
      </div>
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   Profil & notifications (partagé étudiant/enseignant)
══════════════════════════════════════════════════════════════ */
export function ProfileScreen({ role }: { role: 'student'|'teacher'|'admin' }) {
  const [tab, setTab] = useState('profil')
  const tabs = [{id:'profil',label:'Profil'},{id:'notifs',label:'Notifications'},{id:'securite',label:'Sécurité'}]
  const [displayName, setDisplayName] = useState(role==='teacher'?'M. Alaoui':'Yassine Benali')
  const [locale, setLocale] = useState('fr')
  const [notifPrefs, setNotifPrefs] = useState({ joinRequest:true, quizPublished:true, gradePosted:true, announcement:true })

  const notifications = [
    { text: 'Le quiz "HTML/CSS Avancé" est maintenant disponible', time: 'Il y a 2 h', read: false },
    { text: 'M. Alaoui a publié une nouvelle annonce', time: 'Il y a 5 h', read: false },
    { text: 'Votre demande d\'adhésion a été acceptée', time: 'Hier', read: true },
    { text: 'Note du TP1 disponible : 16/20', time: 'Il y a 3 j', read: true },
  ]

  return (
    <div className="max-w-xl mx-auto px-5 md:px-8 py-6">
      <div className="flex items-center gap-4 mb-7">
        <Avatar name={displayName} size="lg" color="var(--accent)" textColor="white"/>
        <div>
          <h1 className="font-display text-xl font-semibold">{displayName}</h1>
          <p className="text-sm text-[var(--muted-foreground)]">OFPPT Casablanca · {role==='teacher'?'Enseignant':role==='admin'?'Super Admin':'Étudiant'}</p>
        </div>
      </div>

      <div className="mb-5"><Tabs tabs={tabs} active={tab} onChange={setTab}/></div>

      {tab === 'profil' && (
        <Card className="p-6 space-y-4">
          <Input label="Nom d'affichage" value={displayName} onChange={setDisplayName}/>
          <div>
            <p className="text-sm font-medium mb-1.5">Langue de l'interface</p>
            <div className="flex gap-2">
              {[{v:'fr',l:'Français'},{v:'ar',l:'العربية'},{v:'en',l:'English'}].map(l => (
                <button key={l.v} onClick={()=>setLocale(l.v)}
                  className={`flex-1 py-2 text-sm rounded-lg border transition-all ${locale===l.v?'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)] font-medium':'border-[var(--border)] text-[var(--muted-foreground)]'}`}>
                  {l.l}
                </button>
              ))}
            </div>
          </div>
          <Btn full>Enregistrer</Btn>
        </Card>
      )}

      {tab === 'notifs' && (
        <div className="space-y-3">
          <Card className="p-5 space-y-3">
            <p className="font-medium text-sm">Préférences de notification</p>
            {[
              {k:'joinRequest',l:'Demandes d\'adhésion'},
              {k:'quizPublished',l:'Quiz publiés'},
              {k:'gradePosted',l:'Notes disponibles'},
              {k:'announcement',l:'Nouvelles annonces'},
            ].map(n => (
              <div key={n.k} className="flex items-center justify-between">
                <span className="text-sm">{n.l}</span>
                <Toggle checked={notifPrefs[n.k as keyof typeof notifPrefs]}
                  onChange={v => setNotifPrefs(p => ({ ...p, [n.k]: v }))}/>
              </div>
            ))}
          </Card>

          <h2 className="font-semibold text-sm mt-5 mb-2">Récentes</h2>
          <Card className="divide-y divide-[var(--border)]">
            {notifications.map((n, i) => (
              <div key={i} className={`px-4 py-3 flex items-start gap-3 ${!n.read?'bg-blue-50/50':''}`}>
                {!n.read && <span className="w-2 h-2 rounded-full bg-[var(--primary)] shrink-0 mt-1.5"/>}
                {n.read && <span className="w-2 h-2 shrink-0"/>}
                <div className="flex-1">
                  <p className="text-xs leading-relaxed">{n.text}</p>
                  <p className="text-[10px] text-[var(--muted-foreground)] mt-0.5">{n.time}</p>
                </div>
              </div>
            ))}
          </Card>
        </div>
      )}

      {tab === 'securite' && (
        <Card className="p-6 space-y-5">
          <div>
            <p className="font-medium text-sm mb-1">Méthode de connexion</p>
            <div className="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-[var(--secondary)]">
              <svg className="w-5 h-5 shrink-0" viewBox="0 0 21 21" fill="none">
                <rect x="0" y="0" width="10" height="10" fill="#F25022"/><rect x="11" y="0" width="10" height="10" fill="#7FBA00"/>
                <rect x="0" y="11" width="10" height="10" fill="#00A4EF"/><rect x="11" y="11" width="10" height="10" fill="#FFB900"/>
              </svg>
              <span className="text-sm">Microsoft OFPPT</span>
              <Badge label="Actif" color="green"/>
            </div>
          </div>
          <div className="border-t border-[var(--border)] pt-4">
            <p className="font-medium text-sm mb-1">Sessions actives</p>
            <p className="text-xs text-[var(--muted-foreground)] mb-2">1 session active — Chrome / Windows · Casablanca</p>
            <Btn size="sm" variant="danger">Se déconnecter de toutes les sessions</Btn>
          </div>
        </Card>
      )}
    </div>
  )
}

/* ══════════════════════════════════════════════════════════════
   B5 — Gestion des demandes (enseignant standalone)
══════════════════════════════════════════════════════════════ */
export function RequestsScreen() {
  const [requests, setRequests] = useState([
    { id:1, name:'Amine Berrada', class:'Développement Web', date:'30 sept. 2026', status:'pending' },
    { id:2, name:'Fatima Zahra Haddou', class:'Développement Web', date:'29 sept. 2026', status:'pending' },
    { id:3, name:'Omar Ziani', class:'Algorithmique', date:'28 sept. 2026', status:'pending' },
    { id:4, name:'Leila Mansouri', class:'Algorithmique', date:'28 sept. 2026', status:'accepted' },
    { id:5, name:'Rachid Oulhaj', class:'Développement Web', date:'27 sept. 2026', status:'rejected' },
  ])

  function decide(id: number, decision: 'accepted'|'rejected') {
    setRequests(rs => rs.map(r => r.id===id ? {...r, status:decision} : r))
  }

  const pending = requests.filter(r => r.status==='pending')
  const decided = requests.filter(r => r.status!=='pending')

  return (
    <div className="max-w-3xl mx-auto px-5 md:px-8 py-6">
      <PageHeader title="Demandes d'adhésion" subtitle={`${pending.length} demande${pending.length>1?'s':''} en attente`}
        actions={<Badge label={`${pending.length} en attente`} color={pending.length>0?'orange':'default'}/>}/>

      {pending.length === 0 && (
        <Card className="p-8 text-center mb-5">
          <div className="w-12 h-12 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-2"><Icons.Check/></div>
          <p className="font-medium text-sm">Aucune demande en attente.</p>
        </Card>
      )}

      {pending.length > 0 && (
        <div className="space-y-2.5 mb-6">
          <h2 className="text-sm font-semibold flex items-center gap-2 mb-3">
            <span className="w-2 h-2 rounded-full bg-[var(--warning)] inline-block"/>En attente
          </h2>
          {pending.map(r => (
            <Card key={r.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
              <Avatar name={r.name}/>
              <div className="flex-1 min-w-0">
                <p className="font-medium text-sm">{r.name}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{r.class} · {r.date}</p>
              </div>
              <div className="flex gap-2">
                <button onClick={() => decide(r.id,'rejected')}
                  className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-[var(--border)] hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition-all">
                  <Icons.X/>Refuser
                </button>
                <button onClick={() => decide(r.id,'accepted')}
                  className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-white rounded-lg transition-all" style={{ background:'#16A34A' }}>
                  <Icons.Check/>Accepter
                </button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {decided.length > 0 && (
        <div>
          <h2 className="text-sm font-semibold text-[var(--muted-foreground)] mb-3">Traitées récemment</h2>
          <div className="space-y-2">
            {decided.map(r => (
              <Card key={r.id} className="px-4 py-3 flex items-center gap-3 opacity-70">
                <Avatar name={r.name} color="var(--muted)" textColor="var(--muted-foreground)"/>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm">{r.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{r.class} · {r.date}</p>
                </div>
                <Badge label={r.status==='accepted'?'Accepté':'Refusé'} color={r.status==='accepted'?'green':'red'}/>
              </Card>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
