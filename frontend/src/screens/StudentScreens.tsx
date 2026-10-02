import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useI18n } from '../i18n'
import { useAuth } from '../context/AuthContext'
import { errorMessage, firstFieldError } from '../lib/api'
import {
  assignments,
  classrooms,
  content,
  flashcards,
  joinRequests,
  partners,
  progression,
  quizzes,
} from '../lib/endpoints'
import { useAction, useAsync } from '../lib/useAsync'
import type {
  ApiAnnouncement,
  ApiAssignment,
  ApiAttemptResult,
  ApiAttemptStart,
  ApiFlashcardDeck,
  ApiMaterial,
  ApiStudentQuiz,
  ApiSubmission,
} from '../lib/types'
import {
  Alert,
  AsyncBoundary,
  Badge,
  Btn,
  Card,
  EmptyState,
  Icons,
  PageHeader,
  ProgressBar,
  SkeletonStats,
  SkeletonList,
  StatTile,
  Tabs,
} from '../components/UI'

/* ══ Tableau de bord étudiant ═══════════════════════════════════════ */

export function StudentDashboard() {
  const { t, formatDate } = useI18n()
  const { user } = useAuth()

  const classes = useAsync(signal => classrooms.list({ signal }), [])
  const progress = useAsync(signal => progression.me({ signal }), [])

  const active = classes.data?.data.filter(c => c.status === 'active') ?? []
  const totals = progress.data?.totals
  const recent = useMemo(() => (progress.data?.history ?? []).slice(0, 5), [progress.data])

  return (
    <div>
      <PageHeader
        title={t('student.greeting', { name: (user?.display_name ?? '').split(' ')[0] })}
        subtitle={t('common.tagline')}
      />

      {progress.loading ? (
        <SkeletonStats/>
      ) : progress.error ? (
        <Alert message={errorMessage(progress.error, t('error.unknown'))} type="error"/>
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
          <StatTile label={t('student.stats.classes')} value={String(active.length)}/>
          <StatTile label={t('student.stats.quizzes')} value={String(totals?.quizzes_taken ?? 0)} color="var(--accent)"/>
          <StatTile label={t('student.stats.average')} value={`${totals?.average_percentage ?? 0}%`} color="green"/>
          <StatTile label={t('student.best')} value={`${totals?.best_percentage ?? 0}%`} color="var(--primary)"/>
        </div>
      )}

      <div className="flex items-center justify-between mb-4">
        <h2 className="font-display text-lg font-semibold">{t('student.myClasses')}</h2>
        <Link to="/app/join">
          <Btn size="sm" variant="secondary">
            <Icons.Plus/> {t('nav.join')}
          </Btn>
        </Link>
      </div>

      <AsyncBoundary
        loading={classes.loading}
        error={classes.error}
        onRetry={classes.reload}
        errorMessage={t('common.error')}
        isEmpty={active.length === 0}
        empty={
          <EmptyState
            message={t('student.noClasses')}
            action={
              <Link to="/app/join">
                <Btn size="sm">{t('join.title')}</Btn>
              </Link>
            }
          />
        }
      >
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {active.map(classroom => (
            <Link key={classroom.id} to={`/app/classes/${classroom.id}`}>
              <Card className="p-5 h-full hover:border-[var(--primary)]/40 transition-colors">
                <div className="flex items-start justify-between gap-2 mb-2">
                  <h3 className="font-semibold text-sm leading-snug">{classroom.name}</h3>
                  <Badge label={classroom.membership?.status === 'accepted' ? t('class.status.active') : t('join.status.pending')} color="green"/>
                </div>
                <p className="text-xs text-[var(--muted-foreground)] mb-3">
                  {classroom.subject} · {classroom.group_label}
                </p>
                <p className="text-xs text-[var(--muted-foreground)] flex items-center gap-1.5">
                  <Icons.Users/> {t('student.members', { count: classroom.members_count ?? 0 })}
                </p>
              </Card>
            </Link>
          ))}
        </div>
      </AsyncBoundary>

      {recent.length > 0 && (
        <>
          <h2 className="font-display text-lg font-semibold mt-8 mb-4">{t('student.upcoming')}</h2>
          <Card className="divide-y divide-[var(--border)]">
            {recent.map(item => (
              <Link
                key={item.attempt_id}
                to={`/app/classes/${item.classroom_id ?? ''}/quizzes/${item.quiz_id}`}
                className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-[var(--muted)]"
              >
                <div className="min-w-0">
                  <p className="text-sm font-medium truncate">{item.quiz_title ?? '—'}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {t('quiz.answered', { count: 1 })} · {formatDate(item.submitted_at)}
                  </p>
                </div>
                <Badge
                  label={`${item.percentage}%`}
                  color={item.percentage >= 50 ? 'green' : 'red'}
                />
              </Link>
            ))}
          </Card>
        </>
      )}
    </div>
  )
}

/* ══ Mes classes ═════════════════════════════════════════════════════ */

export function MyClassesScreen() {
  const { t } = useI18n()
  const classes = useAsync(signal => classrooms.list({ signal }), [])

  return (
    <div>
      <PageHeader
        title={t('nav.classes')}
        actions={
          <Link to="/app/join">
            <Btn size="sm"><Icons.Plus/> {t('nav.join')}</Btn>
          </Link>
        }
      />

      <AsyncBoundary
        loading={classes.loading}
        error={classes.error}
        onRetry={classes.reload}
        errorMessage={t('common.error')}
        isEmpty={(classes.data?.data.length ?? 0) === 0}
        empty={<EmptyState message={t('student.noClasses')}/>}
      >
        <div className="grid sm:grid-cols-2 gap-4">
          {(classes.data?.data ?? []).map(classroom => (
            <Link key={classroom.id} to={`/app/classes/${classroom.id}`}>
              <Card className="p-5 h-full hover:border-[var(--primary)]/40 transition-colors">
                <div className="flex items-start justify-between gap-2 mb-1">
                  <h3 className="font-semibold">{classroom.name}</h3>
                  <Badge
                    label={t(classroom.status === 'archived' ? 'class.status.archived' : 'class.status.active')}
                    color={classroom.status === 'archived' ? 'default' : 'green'}
                  />
                </div>
                <p className="text-xs text-[var(--muted-foreground)]">
                  {classroom.subject} · {classroom.group_label} · {classroom.school_year}
                </p>
              </Card>
            </Link>
          ))}
        </div>
      </AsyncBoundary>
    </div>
  )
}

/* ══ Détail d'une classe (étudiant) ══════════════════════════════════ */

type TabId = 'announcements' | 'materials' | 'quizzes' | 'members' | 'assignments' | 'flashcards'

export function StudentClassScreen() {
  const { id } = useParams()
  const classroomId = Number(id)
  const { t, formatDate } = useI18n()
  const [tab, setTab] = useState<TabId>('announcements')

  const detail = useAsync(signal => classrooms.show(classroomId, { signal }), [classroomId])
  const announcements = useAsync(signal => content.announcements(classroomId, { signal }), [classroomId])
  const materials = useAsync(signal => content.materials(classroomId, { signal }), [classroomId])
  const quizList = useAsync(signal => quizzes.listForStudent(classroomId, { signal }), [classroomId])
  const members = useAsync(signal => classrooms.members(classroomId, { signal }), [classroomId])
  const homework = useAsync(signal => assignments.list(classroomId, { signal }), [classroomId])
  const decks = useAsync(signal => flashcards.list(classroomId, { signal }), [classroomId])

  const tabs = [
    { id: 'announcements' as const, label: t('class.tab.announcements') },
    { id: 'materials' as const, label: t('class.tab.materials') },
    { id: 'quizzes' as const, label: t('class.tab.quizzes') },
    { id: 'assignments' as const, label: t('manage.tab.assignments') },
    { id: 'flashcards' as const, label: t('flashcards.tab') },
    { id: 'members' as const, label: t('class.tab.members') },
  ]

  if (detail.error) return <Alert message={t('error.notMember')} type="error"/>

  return (
    <div>
      <PageHeader
        title={detail.data?.name ?? t('common.loading')}
        subtitle={detail.data ? `${detail.data.subject} · ${detail.data.group_label}` : undefined}
        back={() => window.history.back()}
      />

      <Tabs tabs={tabs} active={tab} onChange={value => setTab(value as TabId)}/>

      <div className="mt-5">
        {tab === 'announcements' && (
          <AnnouncementList items={announcements.data?.data ?? []} loading={announcements.loading} error={announcements.error} onRetry={announcements.reload} formatDate={formatDate}/>
        )}

        {tab === 'materials' && (
          <MaterialList items={materials.data?.data ?? []} loading={materials.loading} error={materials.error} onRetry={materials.reload}/>
        )}

        {tab === 'quizzes' && (
          <StudentQuizList items={quizList.data?.data ?? []} loading={quizList.loading} error={quizList.error} onRetry={quizList.reload} classroomId={classroomId}/>
        )}

        {tab === 'members' && (
          <MemberList items={members.data?.data ?? []} loading={members.loading} error={members.error} onRetry={members.reload}/>
        )}

        {tab === 'assignments' && (
          <AssignmentList items={homework.data?.data ?? []} loading={homework.loading} error={homework.error} onRetry={homework.reload}/>
        )}

        {tab === 'flashcards' && (
          <FlashcardList items={decks.data?.data ?? []} loading={decks.loading} error={decks.error} onRetry={decks.reload}/>
        )}
      </div>
    </div>
  )
}

function AnnouncementList({
  items,
  loading,
  error,
  onRetry,
  formatDate,
}: {
  items: ApiAnnouncement[]
  loading: boolean
  error: Error | null
  onRetry: () => void
  formatDate: (v: string | null) => string
}) {
  const { t } = useI18n()
  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('class.noAnnouncements')}/>}
    >
      <div className="space-y-3">
        {[...items].sort((a, b) => Number(b.pinned) - Number(a.pinned)).map(item => (
          <Card key={item.id} className="p-4">
            <div className="flex items-center gap-2 mb-1.5">
              {item.pinned && <Badge label={t('class.pinned')} color="orange"/>}
              <h3 className="font-semibold text-sm">{item.title}</h3>
            </div>
            <p className="text-sm text-[var(--muted-foreground)] whitespace-pre-wrap mb-2">{item.body}</p>
            <p className="text-xs text-[var(--muted-foreground)]">
              {item.author?.display_name ?? '—'} · {formatDate(item.created_at)}
            </p>
          </Card>
        ))}
      </div>
    </AsyncBoundary>
  )
}

function MaterialList({
  items,
  loading,
  error,
  onRetry,
}: {
  items: ApiMaterial[]
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  const { t, locale, formatNumber } = useI18n()
  const [busy, setBusy] = useState<number | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  async function download(material: ApiMaterial) {
    setBusy(material.id)
    setFailure(null)
    try {
      await content.downloadMaterial(material.id, material.file_name ?? `ressource-${material.id}`, { locale })
    } catch (cause) {
      setFailure(errorMessage(cause, t('error.noFile')))
    } finally {
      setBusy(null)
    }
  }

  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('class.noMaterials')}/>}
    >
      <div className="space-y-2.5">
        {failure && <Alert message={failure} type="error"/>}
        {items.map(item => (
          <Card key={item.id} className="p-4 flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style={{ background: 'var(--secondary)', color: 'var(--primary)' }}>
              {item.type === 'link' ? <Icons.External/> : <Icons.Book/>}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium truncate">{item.title}</p>
              <p className="text-xs text-[var(--muted-foreground)]">
                {t(item.type === 'file' ? 'class.type.file' : 'class.type.link')}
                {item.chapter ? ` · ${item.chapter}` : ''}
                {item.file_size ? ` · ${formatNumber(item.file_size)} o` : ''}
              </p>
            </div>
            {item.type === 'link' && item.url ? (
              <a
                href={item.url}
                target="_blank"
                rel="noopener noreferrer"
                title={t('common.open')}
                aria-label={t('common.open')}
                className="p-1.5 rounded-lg text-[var(--muted-foreground)] hover:bg-[var(--muted)]"
              >
                <Icons.External/>
              </a>
            ) : item.has_file ? (
              <Btn size="sm" variant="secondary" onClick={() => void download(item)} disabled={busy === item.id}>
                {t('common.download')}
              </Btn>
            ) : null}
          </Card>
        ))}
      </div>
    </AsyncBoundary>
  )
}

function MemberList({
  items,
  loading,
  error,
  onRetry,
}: {
  items: import('../lib/types').ApiMember[]
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  const { t } = useI18n()
  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('class.noMembers')}/>}
    >
      <Card className="divide-y divide-[var(--border)]">
        {items.map(member => (
          <div key={member.membership_id} className="flex items-center gap-3 px-4 py-3">
            <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style={{ background: 'var(--accent)', color: 'white' }}>
              {member.user?.initials ?? '—'}
            </div>
            <p className="text-sm flex-1">{member.user?.display_name ?? '—'}</p>
            <Badge
              label={t(
                member.status === 'accepted'
                  ? 'manage.accepted'
                  : member.status === 'pending'
                    ? 'join.status.pending'
                    : 'join.status.rejected',
              )}
              color={member.status === 'accepted' ? 'green' : member.status === 'pending' ? 'orange' : 'red'}
            />
          </div>
        ))}
      </Card>
    </AsyncBoundary>
  )
}

function AssignmentList({
  items,
  loading,
  error,
  onRetry,
}: {
  items: ApiAssignment[]
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  const { t, formatDateTime } = useI18n()
  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('grade.noAssignments')}/>}
    >
      <div className="space-y-2.5">
        {items.map(item => (
          <Link key={item.id} to={`/app/assignments/${item.id}`} className="block">
            <Card className="p-4 flex items-center gap-3 hover:border-[var(--primary)] transition-colors">
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium">{item.title}</p>
                <p className="text-xs text-[var(--muted-foreground)]">
                  {item.due_at ? formatDateTime(item.due_at) : '—'}
                </p>
              </div>
              {item.my_submission ? (
                item.my_submission.grade !== null ? (
                  <Badge label={`${item.my_submission.grade} / 20`} color="green"/>
                ) : (
                  <Badge label={t('assignment.grade.pending')} color="blue"/>
                )
              ) : item.is_overdue ? (
                <Badge label={t('deadlines.overdue')} color="red"/>
              ) : (
                <Badge label={t('assignment.submit.send')} color="orange"/>
              )}
              <Icons.ChevRight/>
            </Card>
          </Link>
        ))}
      </div>
    </AsyncBoundary>
  )
}

/* ══ Dépôt d'un rendu (F-DEV-02) ═════════════════════════════════════ */

export function AssignmentDetailScreen() {
  const { assignmentId: rawId } = useParams()
  const assignmentId = Number(rawId)
  const { t, formatDateTime } = useI18n()
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const send = useAction()
  const download = useAction()

  // F-DEV-02 : le detail porte `my_submission`, donc le depot et la note
  // sont lus depuis la meme ressource que la liste.
  const assignment = useAsync(signal => assignments.show(assignmentId, { signal }), [assignmentId])

  const item = assignment.data
  const mine: ApiSubmission | null = item?.my_submission ?? null

  async function submit() {
    if (!file) {
      setFileError(t('assignment.error.noFile'))
      return
    }
    if (file.size > 10 * 1024 * 1024) {
      setFileError(t('assignment.error.tooLarge'))
      return
    }
    setFileError(null)
    setNotice(null)
    const result = await send.run(() => assignments.submit(assignmentId, file))
    if (result) {
      setFile(null)
      setNotice(t('common.saved'))
      assignment.reload()
    }
  }

  async function openFile() {
    if (!mine?.file_name) return
    await download.run(() => assignments.downloadSubmission(mine.id, mine.file_name as string))
  }

  return (
    <div className="max-w-2xl">
      <PageHeader
        title={item?.title ?? t('common.loading')}
        subtitle={item ? (item.due_at ? t('assignment.dueAt') + ' : ' + formatDateTime(item.due_at) : t('assignment.noDueDate')) : undefined}
        back={() => window.history.back()}
      />

      <AsyncBoundary
        loading={assignment.loading}
        error={assignment.error}
        onRetry={assignment.reload}
        errorMessage={t('common.error')}
      >
        <>
          {item?.instructions && (
            <Card className="p-5 mb-4">
              <p className="text-sm font-semibold mb-2">{t('assignment.instructions')}</p>
              <p className="text-sm text-[var(--muted-foreground)] whitespace-pre-wrap">{item.instructions}</p>
            </Card>
          )}

          {notice && <div className="mb-4"><Alert message={notice} type="success"/></div>}
          {send.error && (
            <div className="mb-4">
              <Alert message={errorMessage(send.error, t('error.unknown'))} type="error"/>
            </div>
          )}

          {mine ? (
            <Card className="p-5 space-y-4">
              <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">{t('assignment.submitted.title')}</p>
                <Badge
                  label={mine.is_late ? t('assignment.submitted.late') : t('assignment.submitted.onTime')}
                  color={mine.is_late ? 'red' : 'green'}
                />
              </div>
              <p className="text-xs text-[var(--muted-foreground)]">
                {t('assignment.submitted.at', { date: formatDateTime(mine.submitted_at) })}
              </p>

              <div className="flex items-center justify-between p-3 rounded-lg" style={{ background: 'var(--muted)' }}>
                <span className="text-sm truncate">{mine.file_name ?? t('error.noFile')}</span>
                <Btn size="sm" variant="secondary" onClick={() => void openFile()} disabled={download.pending}>
                  {t('assignment.download')}
                </Btn>
              </div>

              <div className="pt-2 border-t border-[var(--border)]">
                <p className="text-sm font-semibold mb-1">{t('assignment.grade.title')}</p>
                {mine.grade === null ? (
                  <p className="text-sm text-[var(--muted-foreground)]">{t('assignment.grade.pending')}</p>
                ) : (
                  <p className="text-sm font-medium text-green-600">
                    {t('assignment.grade.value', { grade: mine.grade })}
                  </p>
                )}
                {mine.feedback && (
                  <>
                    <p className="text-sm font-semibold mt-3 mb-1">{t('assignment.feedback')}</p>
                    <p className="text-sm text-[var(--muted-foreground)] whitespace-pre-wrap">{mine.feedback}</p>
                  </>
                )}
              </div>
            </Card>
          ) : (
            <Card className="p-5 space-y-3">
              <p className="text-sm font-semibold">{t('assignment.submit.title')}</p>
              <p className="text-xs text-[var(--muted-foreground)]">{t('assignment.submit.hint')}</p>

              <label className="flex flex-col items-center justify-center gap-2 px-4 py-6 border-2 border-dashed border-[var(--border)] rounded-lg cursor-pointer hover:border-[var(--primary)] transition-colors">
                <Icons.Upload/>
                <span className="text-sm font-medium">{file ? file.name : t('assignment.submit.choose')}</span>
                <input
                  type="file"
                  className="hidden"
                  onChange={event => {
                    setFile(event.target.files?.[0] ?? null)
                    setFileError(null)
                  }}
                />
              </label>
              {fileError && <p className="text-xs text-[var(--danger)]">{fileError}</p>}

              <Btn full onClick={() => void submit()} disabled={send.pending || !file}>
                {send.pending ? t('assignment.submit.sending') : t('assignment.submit.send')}
              </Btn>
            </Card>
          )}
        </>
      </AsyncBoundary>
    </div>
  )
}

/* ══ F-QUI-08 : révision par flashcards ══════════════════════════════ */

function FlashcardList({
  items,
  loading,
  error,
  onRetry,
}: {
  items: ApiFlashcardDeck[]
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  const { t } = useI18n()
  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('flashcards.none')}/>}
    >
      <div className="space-y-2.5">
        {items.map(deck => (
          <Link key={deck.id} to={`/app/flashcard-decks/${deck.id}`} className="block">
            <Card className="p-4 flex items-center gap-3 hover:border-[var(--primary)] transition-colors">
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium">{deck.title}</p>
                <p className="text-xs text-[var(--muted-foreground)]">
                  {t('flashcards.cardsCount', { count: deck.cards_count })}
                </p>
              </div>
              {deck.source === 'ai' && <Badge label="IA" color="purple"/>}
              <span className="text-[var(--muted-foreground)] shrink-0"><Icons.ChevRight/></span>
            </Card>
          </Link>
        ))}
      </div>
    </AsyncBoundary>
  )
}

export function FlashcardStudyScreen() {
  const { deckId: rawId } = useParams()
  const deckId = Number(rawId)
  const { t } = useI18n()
  const [index, setIndex] = useState(0)
  const [flipped, setFlipped] = useState(false)
  const [saving, setSaving] = useState(false)

  const deck = useAsync(signal => flashcards.show(deckId, { signal }), [deckId])
  const cards = deck.data?.cards ?? []
  // `card` est `undefined` pendant le chargement et sur un deck vide : le
  // JSX ci-dessous est évalué avant le rendu d'`AsyncBoundary`, donc l'accès
  // doit rester optionnel, sinon l'écran levait une TypeError et la requête
  // n'était même pas émise.
  const card = cards[index]

  // Un changement de deck remet le parcours à zéro.
  useEffect(() => {
    setIndex(0)
    setFlipped(false)
  }, [deckId])

  /**
   * F-QUI-08 : « su » / « à revoir » est enregistré côté serveur
   * (`flashcard_reviews`), donc l'état survit au rechargement. On applique
   * d'abord l'état localement pour que l'interface reste immédiate, puis on
   * synchronise.
   */
  const mark = async (known: boolean) => {
    if (!card || saving) return

    const cardId = card.id
    const goNext = () => {
      setIndex(value => (value + 1) % cards.length)
      setFlipped(false)
    }

    setSaving(true)
    try {
      await flashcards.reviewCard(deckId, cardId, known)
      deck.setData(previous =>
        previous
          ? {
              ...previous,
              cards: previous.cards?.map(c => (c.id === cardId ? { ...c, known } : c)),
            }
          : previous,
      )
      goNext()
    } catch {
      // Échec de synchronisation : on reste sur la carte, l'état local n'a
      // pas été modifié et l'étudiant peut réessayer.
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="max-w-xl">
      <PageHeader
        title={deck.data?.title ?? t('common.loading')}
        subtitle={t('flashcards.subtitle')}
        back={() => window.history.back()}
      />

      <AsyncBoundary
        loading={deck.loading}
        error={deck.error}
        onRetry={deck.reload}
        errorMessage={t('common.error')}
        isEmpty={cards.length === 0}
        empty={<EmptyState message={t('flashcards.empty')}/>}
      >
        <>
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs text-[var(--muted-foreground)]">
              {t('flashcards.position', { current: index + 1, total: cards.length })}
            </span>
            {!flipped && <span className="text-xs text-[var(--muted-foreground)]">{t('flashcards.flip')}</span>}
          </div>

          <button
            onClick={() => setFlipped(v => !v)}
            className="w-full min-h-56 rounded-[var(--radius)] border border-[var(--border)] bg-white p-8 flex flex-col items-center justify-center gap-3 text-center shadow-sm hover:border-[var(--primary)] transition-colors"
            aria-label={t('flashcards.flip')}
          >
            <p className="font-display text-lg font-semibold">{card?.front}</p>
            {flipped && (
              <>
                <span className="w-10 h-px" style={{ background: 'var(--border)' }}/>
                <p className="text-sm text-[var(--muted-foreground)] whitespace-pre-wrap">{card?.back}</p>
              </>
            )}
          </button>

          {card?.known !== null && card?.known !== undefined && (
            <p className="mt-3 text-center text-xs text-[var(--muted-foreground)]">
              {card.known ? t('flashcards.knownBefore') : t('flashcards.reviewBefore')}
            </p>
          )}

          <ProgressBar value={cards.length ? ((index + 1) / cards.length) * 100 : 0}/>

          <div className="flex items-center justify-center gap-2 mt-4">
            <Btn variant="ghost" onClick={() => mark(false)} disabled={saving}>
              {t('flashcards.again')}
            </Btn>
            <Btn onClick={() => mark(true)} disabled={saving}>
              {t('flashcards.known')}
            </Btn>
          </div>

          <div className="flex items-center justify-between mt-3">
            <span className="text-xs text-[var(--muted-foreground)]">
              {t('flashcards.progress', {
                known: cards.filter(c => c.known === true).length,
                total: cards.length,
              })}
            </span>
          </div>

          <div className="flex items-center justify-between mt-1">
            <Btn
              variant="ghost"
              onClick={() => {
                setIndex(value => Math.max(0, value - 1))
                setFlipped(false)
              }}
              disabled={index === 0}
            >
              {t('flashcards.previous')}
            </Btn>
            {index === cards.length - 1 ? (
              <Btn
                variant="ghost"
                onClick={() => {
                  setIndex(0)
                  setFlipped(false)
                }}
              >
                {t('flashcards.restart')}
              </Btn>
            ) : (
              <Btn
                variant="ghost"
                onClick={() => {
                  setIndex(value => Math.min(cards.length - 1, value + 1))
                  setFlipped(false)
                }}
              >
                {t('flashcards.next')}
              </Btn>
            )}
          </div>
        </>
      </AsyncBoundary>
    </div>
  )
}

function StudentQuizList({
  items,
  loading,
  error,
  onRetry,
  classroomId,
}: {
  items: (ApiStudentQuiz | import('../lib/types').ApiQuiz)[]
  loading: boolean
  error: Error | null
  onRetry: () => void
  classroomId: number
}) {
  const { t } = useI18n()
  const published = items.filter(item => item.status === 'published')

  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onRetry}
      errorMessage={t('common.error')}
      isEmpty={published.length === 0}
      empty={<EmptyState message={t('class.noQuizzes')}/>}
    >
      <div className="space-y-2.5">
        {published.map(item => (
          <Card key={item.id} className="p-4 flex items-center gap-3">
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium">{item.title}</p>
              <p className="text-xs text-[var(--muted-foreground)]">
                {item.time_limit_min ? t('quiz.minutes', { count: item.time_limit_min }) : '∞'}
                {'attempts_used' in item && item.max_attempts
                  ? ` · ${t('class.attempts', { used: item.attempts_used ?? 0, max: item.max_attempts })}`
                  : ''}
              </p>
            </div>
            <Link to={`/app/classes/${classroomId}/quizzes/${item.id}`}>
              <Btn size="sm">{'attempts_used' in item && (item.attempts_used ?? 0) > 0 ? t('class.retake') : t('class.start')}</Btn>
            </Link>
          </Card>
        ))}
      </div>
    </AsyncBoundary>
  )
}

/* ══ Passation d'un quiz ═════════════════════════════════════════════ */

export function QuizAttemptScreen() {
  const { id, classroomId } = useParams()
  const quizId = Number(id)
  const { t } = useI18n()
  const navigate = useNavigate()

  const [attempt, setAttempt] = useState<ApiAttemptStart | null>(null)
  const [result, setResult] = useState<ApiAttemptResult | null>(null)
  const [answers, setAnswers] = useState<Record<number, number[]>>({})
  const [index, setIndex] = useState(0)
  const [remaining, setRemaining] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const start = useAction()

  /* Minuteur : le serveur reste l'arbitre, l'UI ne fait qu'afficher. */
  useEffect(() => {
    if (!attempt || attempt.time_limit_min === null) return

    setRemaining(attempt.remaining_seconds)
    const timer = window.setInterval(() => {
      setRemaining(value => {
        if (value <= 1) {
          window.clearInterval(timer)
          void submit(true)
          return 0
        }
        return value - 1
      })
    }, 1000)

    return () => window.clearInterval(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt?.attempt_id])

  async function begin() {
    setError(null)
    const started = await start.run(() => quizzes.startAttempt(quizId))
    if (started) {
      setAttempt(started as ApiAttemptStart)
      setIndex(0)
      setAnswers({})
    } else {
      setError(errorMessage(start.error, t('error.maxAttempts')))
    }
  }

  async function submit(auto = false) {
    if (!attempt) return
    setError(null)
    const payload = {
      answers: Object.entries(answers).map(([questionId, selected]) => ({
        question_id: Number(questionId),
        selected_option_ids: selected,
      })),
    }
    try {
      const outcome = await quizzes.submitAttempt(attempt.attempt_id, payload)
      setResult(outcome)
    } catch (cause) {
      if (!auto) setError(errorMessage(cause, t('error.unknown')))
    }
  }

  function toggle(questionId: number, optionId: number, multiple: boolean) {
    setAnswers(current => {
      const existing = current[questionId] ?? []
      if (multiple) {
        return {
          ...current,
          [questionId]: existing.includes(optionId)
            ? existing.filter(id => id !== optionId)
            : [...existing, optionId],
        }
      }
      return { ...current, [questionId]: [optionId] }
    })
  }

  if (result) {
    const wrong = result.answers.filter(a => !a.is_correct).length
    return (
      <div className="max-w-2xl mx-auto">
        <Card className="p-6 text-center">
          <h1 className="font-display text-2xl font-semibold mb-2">{t('quiz.result.title')}</h1>
          <p className="text-sm text-[var(--muted-foreground)] mb-6">
            {t('quiz.result.body', { score: result.score, total: result.max_score })}
          </p>
          <div className="grid grid-cols-3 gap-3 mb-6">
            <StatTile label={t('quiz.result.score')} value={`${result.percentage}%`}/>
            <StatTile label={t('quiz.result.correct')} value={String(result.score)} color="green"/>
            <StatTile label={t('quiz.result.wrong')} value={String(wrong)} color="var(--danger)"/>
          </div>
          {result.show_answers ? (
            <div className="text-left space-y-3">
              <h2 className="font-semibold text-sm">{t('quiz.result.detail')}</h2>
              {result.answers.map(answer => (
                <Card key={answer.question_id} className="p-4">
                  <p className="text-sm font-medium mb-2">{answer.statement}</p>
                  <div className="space-y-1.5">
                    {answer.options.map(option => (
                      <div
                        key={option.id}
                        className={`flex items-center gap-2 text-xs px-2.5 py-1.5 rounded-lg ${
                          option.is_correct
                            ? 'bg-green-50 text-green-700'
                            : answer.selected_option_ids.includes(option.id)
                              ? 'bg-red-50 text-red-700'
                              : 'text-[var(--muted-foreground)]'
                        }`}
                      >
                        {option.is_correct ? <Icons.Check/> : null}
                        <span className="flex-1">{option.label}</span>
                      </div>
                    ))}
                  </div>
                  {answer.explanation && (
                    <p className="text-xs text-[var(--muted-foreground)] mt-2">{answer.explanation}</p>
                  )}
                </Card>
              ))}
            </div>
          ) : (
            <p className="text-sm text-[var(--muted-foreground)] mb-6">{t('quiz.result.noCorrection')}</p>
          )}
          <Btn onClick={() => navigate(`/app/classes/${classroomId}`)}>{t('quiz.result.back')}</Btn>
        </Card>
      </div>
    )
  }

  if (!attempt) {
    return (
      <div className="max-w-lg mx-auto">
        <PageHeader title={t('quiz.start')} back={() => navigate(-1)}/>
        {error && <div className="mb-4"><Alert message={error} type="error"/></div>}
        <Card className="p-6 text-center">
          <div className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3" style={{ background: 'var(--secondary)', color: 'var(--primary)' }}>
            <Icons.Quiz/>
          </div>
          <Btn onClick={() => void begin()} disabled={start.pending}>
            {start.pending ? t('quiz.starting') : t('quiz.start')}
          </Btn>
        </Card>
      </div>
    )
  }

  const question = attempt.questions[index]
  const multiple = question?.type?.includes('multiple') || question?.type === 'multiple_choice'
  const answered = Object.values(answers).filter(list => list.length > 0).length

  return (
    <div className="max-w-2xl mx-auto">
      {error && <div className="mb-4"><Alert message={error} type="error"/></div>}

      <div className="flex items-center justify-between mb-2 text-xs text-[var(--muted-foreground)]">
        <span>{t('quiz.questionOf', { current: index + 1, total: attempt.questions.length })}</span>
        <span>
          {answered === 1 ? t('quiz.answered', { count: answered }) : t('quiz.answeredPlural', { count: answered })}
        </span>
      </div>

      {attempt.time_limit_min !== null && (
        <div className="flex items-center gap-2 mb-4 text-sm">
          <Icons.Clock/>
          <span className="font-mono tabular-nums">
            {String(Math.floor(remaining / 60)).padStart(2, '0')}:{String(remaining % 60).padStart(2, '0')}
          </span>
        </div>
      )}

      <ProgressBar value={((index + 1) / attempt.questions.length) * 100}/>

      <Card className="p-6 mt-4">
        <p className="font-semibold mb-4">{question?.statement}</p>
        <div className="space-y-2">
          {question?.options.map(option => {
            const selected = (answers[question.id] ?? []).includes(option.id)
            return (
              <button
                key={option.id}
                onClick={() => toggle(question.id, option.id, multiple)}
                className={`w-full text-left px-4 py-3 rounded-lg border text-sm transition-colors ${
                  selected
                    ? 'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)]'
                    : 'border-[var(--border)] hover:bg-[var(--muted)]'
                }`}
              >
                {option.label}
              </button>
            )
          })}
        </div>
      </Card>

      <div className="flex items-center justify-between mt-4">
        <Btn variant="ghost" onClick={() => setIndex(value => Math.max(0, value - 1))} disabled={index === 0}>
          {t('quiz.previous')}
        </Btn>
        {index < attempt.questions.length - 1 ? (
          <Btn onClick={() => setIndex(value => value + 1)}>{t('quiz.next')}</Btn>
        ) : (
          <Btn variant="success" onClick={() => void submit()}>{t('quiz.submit')}</Btn>
        )}
      </div>
    </div>
  )
}

/* ══ Échéances ══════════════════════════════════════════════════════ */

export function DeadlinesScreen() {
  const { t } = useI18n()
  const deadlines = useAsync(signal => progression.deadlines({ signal }), [])

  const items = deadlines.data?.data ?? []
  const upcoming = items.filter(item => !item.is_overdue)
  const overdue = items.filter(item => item.is_overdue)

  return (
    <div>
      <PageHeader title={t('deadlines.title')} subtitle={t('deadlines.subtitle')}/>

      {deadlines.error && <Alert message={t('common.error')} type="error"/>}

      {overdue.length > 0 && (
        <>
          <h2 className="text-xs font-semibold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">{t('deadlines.overdue')}</h2>
          <div className="space-y-2.5 mb-6">
            {overdue.map(item => (
              <DeadlineRow key={`${item.kind}-${item.id}`} kind={item.kind} title={item.title} classroom={item.classroom} dueAt={item.due_at} overdue/>
            ))}
          </div>
        </>
      )}

      <h2 className="text-xs font-semibold uppercase tracking-wider text-[var(--muted-foreground)] mb-2">{t('deadlines.soon')}</h2>
      {deadlines.loading ? (
        <SkeletonList/>
      ) : items.length === 0 ? (
        <EmptyState message={t('deadlines.empty')}/>
      ) : (
        <div className="space-y-2.5">
          {upcoming.map(item => (
            <DeadlineRow key={`${item.kind}-${item.id}`} kind={item.kind} title={item.title} classroom={item.classroom} dueAt={item.due_at}/>
          ))}
        </div>
      )}
    </div>
  )
}

function DeadlineRow({
  kind,
  title,
  classroom,
  dueAt,
  overdue,
}: {
  kind: 'assignment' | 'quiz'
  title: string
  classroom: string | null
  dueAt: string | null
  overdue?: boolean
}) {
  const { t, formatDateTime } = useI18n()
  return (
    <Card className="p-4 flex items-center gap-3">
      <div
        className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
        style={{ background: overdue ? '#FEE2E2' : 'var(--secondary)', color: overdue ? 'var(--danger)' : 'var(--primary)' }}
      >
        {kind === 'quiz' ? <Icons.Quiz/> : <Icons.Book/>}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium truncate">{title}</p>
        <p className="text-xs text-[var(--muted-foreground)]">{classroom ?? '—'}</p>
      </div>
      <div className="text-right">
        <p className="text-xs text-[var(--muted-foreground)]">{dueAt ? formatDateTime(dueAt) : '—'}</p>
        {overdue && <Badge label={t('deadlines.overdue')} color="red"/>}
      </div>
    </Card>
  )
}

/* ══ Partenaires d'étude ═════════════════════════════════════════════ */

const SKILL_SUGGESTIONS = ['HTML/CSS', 'JavaScript', 'SQL', 'Réseaux', 'Python', 'Anglais', 'Comptabilité', 'Algorithmique']
const AVAILABILITY_KEYS = ['avail.week-evenings', 'avail.weekends', 'avail.anytime'] as const

export function PartnersScreen() {
  const { t, locale, formatDate } = useI18n()
  const { user } = useAuth()
  const [classroomId, setClassroomId] = useState<number | null>(null)
  const [tab, setTab] = useState<'candidates' | 'requests'>('candidates')
  const [newSkill, setNewSkill] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  const profile = useAsync(signal => partners.profile({ signal }), [])
  const classes = useAsync(signal => classrooms.list({ signal }), [])
  const candidates = useAsync(
    signal => (classroomId ? partners.candidates(classroomId, { signal }) : Promise.resolve({ data: [] })),
    [classroomId],
  )
  const requests = useAsync(signal => partners.myRequests({ signal }), [])
  const save = useAction()

  useEffect(() => {
    const first = classes.data?.data[0]
    if (first && classroomId === null) setClassroomId(first.id)
  }, [classes.data, classroomId])

  async function toggleOptIn(enabled: boolean) {
    await save.run(() => partners.updateProfile({ opt_in: enabled }, { locale }))
    profile.reload()
  }

  /** L'API exige `opt_in` : chaque mise à jour renvoie l'état courant. */
  async function patch(payload: { skills?: string[]; availability?: string[] }) {
    const optIn = profile.data?.opt_in ?? false
    const result = await save.run(() => partners.updateProfile({ opt_in: optIn, ...payload }, { locale }))
    if (result) profile.reload()
  }

  async function addSkill() {
    const value = newSkill.trim()
    if (!value) return
    const current = profile.data?.skills ?? []
    if (current.includes(value)) {
      setNewSkill('')
      return
    }
    await patch({ skills: [...current, value] })
    setNewSkill('')
  }

  async function toggleAvailability(key: string) {
    const current = profile.data?.availability ?? []
    const next = current.includes(key) ? current.filter(item => item !== key) : [...current, key]
    await patch({ availability: next })
  }

  async function requestPartner(candidateId: number) {
    if (!classroomId) return
    const result = await save.run(() => partners.request({ to_user_id: candidateId, classroom_id: classroomId }, { locale }))
    if (result) {
      setNotice(t('common.saved'))
      candidates.reload()
    }
  }

  async function respond(requestId: number, status: 'accepted' | 'rejected') {
    await save.run(() => partners.respond(requestId, status, { locale }))
    requests.reload()
  }

  return (
    <div>
      <PageHeader title={t('partners.title')} subtitle={t('partners.subtitle')}/>

      {notice && <div className="mb-4"><Alert message={notice} type="success"/></div>}
      {save.error && <div className="mb-4"><Alert message={errorMessage(save.error, t('error.unknown'))} type="error"/></div>}

      <Card className="p-5 mb-5">
        <div className="flex items-center justify-between gap-3 mb-4">
          <div>
            <p className="text-sm font-medium">{t('partners.optIn')}</p>
            <p className="text-xs text-[var(--muted-foreground)]">{t('partners.optInHint')}</p>
          </div>
          <input
            type="checkbox"
            role="switch"
            checked={profile.data?.opt_in ?? false}
            disabled={profile.loading}
            onChange={event => void toggleOptIn(event.target.checked)}
            className="w-10 h-5 rounded-full appearance-none transition-colors checked:bg-[var(--primary)] bg-[var(--border)]"
            aria-label={t('partners.optIn')}
          />
        </div>

        {profile.data?.opt_in === false && (
          <Alert message={t('partners.inactive')} type="info"/>
        )}

        <div className="mt-4">
          <p className="text-sm font-medium mb-2">{t('partners.skills')}</p>
          <div className="flex flex-wrap gap-1.5 mb-2">
            {(profile.data?.skills ?? []).map(skill => (
              <Badge key={skill} label={skill} color="blue"/>
            ))}
          </div>
          <div className="flex gap-2">
            <input
              value={newSkill}
              onChange={event => setNewSkill(event.target.value)}
              onKeyDown={event => { if (event.key === 'Enter') void addSkill() }}
              placeholder={t('partners.skillsAdd')}
              className="flex-1 px-3 py-1.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
            />
            <Btn size="sm" onClick={() => void addSkill()}><Icons.Plus/></Btn>
          </div>
          <div className="flex flex-wrap gap-1.5 mt-2">
            {SKILL_SUGGESTIONS.filter(skill => !(profile.data?.skills ?? []).includes(skill)).map(skill => (
              <button
                key={skill}
                onClick={() => {
                  setNewSkill(skill)
                }}
                className="text-[10px] px-2 py-0.5 rounded-full bg-[var(--muted)] text-[var(--muted-foreground)] hover:text-[var(--foreground)]"
              >
                + {skill}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-4">
          <p className="text-sm font-medium mb-2">{t('partners.availability')}</p>
          <div className="flex flex-wrap gap-1.5">
            {AVAILABILITY_KEYS.map(key => {
              const active = (profile.data?.availability ?? []).includes(key)
              return (
                <button
                  key={key}
                  onClick={() => void toggleAvailability(key)}
                  className={`text-xs px-2.5 py-1 rounded-full border transition-colors ${
                    active
                      ? 'border-[var(--primary)] bg-[var(--secondary)] text-[var(--primary)]'
                      : 'border-[var(--border)] text-[var(--muted-foreground)]'
                  }`}
                >
                  {t(key)}
                </button>
              )
            })}
          </div>
        </div>
      </Card>

      <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
        <Tabs
          tabs={[
            { id: 'candidates', label: t('partners.title') },
            { id: 'requests', label: t('partners.requests') },
          ]}
          active={tab}
          onChange={value => setTab(value as 'candidates' | 'requests')}
        />
        <select
          value={classroomId ?? ''}
          onChange={event => setClassroomId(Number(event.target.value))}
          className="px-3 py-1.5 text-sm border border-[var(--border)] rounded-lg bg-white outline-none focus:ring-2 focus:ring-[var(--primary)]"
          aria-label={t('partners.selectClass')}
        >
          {(classes.data?.data ?? []).map(item => (
            <option key={item.id} value={item.id}>{item.name}</option>
          ))}
        </select>
      </div>

      {tab === 'candidates' && (
        <AsyncBoundary
          loading={candidates.loading}
          error={candidates.error}
          onRetry={candidates.reload}
          errorMessage={t('common.error')}
          isEmpty={(candidates.data?.data.length ?? 0) === 0}
          empty={<EmptyState message={t('partners.none')}/>}
        >
          <div className="grid sm:grid-cols-2 gap-3">
            {(candidates.data?.data ?? []).map(candidate => (
              <Card key={candidate.user_id} className="p-4">
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold" style={{ background: 'var(--accent)', color: 'white' }}>
                    {(candidate.display_name ?? '?').slice(0, 1).toUpperCase()}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{candidate.display_name ?? '—'}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">{t('partners.match', { percent: candidate.compatibility })}</p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-1.5 mb-3">
                  {candidate.shared_skills.map(skill => (
                    <Badge key={skill} label={skill} color="blue"/>
                  ))}
                </div>
                <Btn size="sm" full onClick={() => void requestPartner(candidate.user_id)} disabled={candidate.user_id === user?.id}>
                  {t('partners.send')}
                </Btn>
              </Card>
            ))}
          </div>
        </AsyncBoundary>
      )}

      {tab === 'requests' && (
        <AsyncBoundary
          loading={requests.loading}
          error={requests.error}
          onRetry={requests.reload}
          errorMessage={t('common.error')}
          isEmpty={(requests.data?.data.length ?? 0) === 0}
          empty={<EmptyState message={t('partners.none')}/>}
        >
          <Card className="divide-y divide-[var(--border)]">
            {(requests.data?.data ?? []).map(request => (
              <div key={request.id} className="p-4 flex items-center gap-3">
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium">
                    {t(request.direction === 'incoming' ? 'partners.incoming' : 'partners.outgoing')} · {request.counterpart?.display_name ?? '—'}
                  </p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {request.classroom_name ?? '—'} · {formatDate(request.created_at)}
                  </p>
                </div>
                {request.direction === 'incoming' && request.status === 'pending' ? (
                  <div className="flex gap-1.5">
                    <Btn size="sm" variant="success" onClick={() => void respond(request.id, 'accepted')}>{t('partners.accept')}</Btn>
                    <Btn size="sm" variant="ghost" onClick={() => void respond(request.id, 'rejected')}>{t('partners.reject')}</Btn>
                  </div>
                ) : (
                  <Badge
                    label={t(request.status === 'accepted' ? 'join.status.accepted' : request.status === 'rejected' ? 'join.status.rejected' : 'join.status.pending')}
                    color={request.status === 'accepted' ? 'green' : request.status === 'rejected' ? 'red' : 'orange'}
                  />
                )}
              </div>
            ))}
          </Card>
        </AsyncBoundary>
      )}
    </div>
  )
}

/* ══ Rejoindre une classe ════════════════════════════════════════════ */

export function JoinClassScreen() {
  const { t } = useI18n()
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sentCode, setSentCode] = useState<string | null>(null)
  const submit = useAction()

  const mine = useAsync(signal => joinRequests.mine({ signal }), [])

  async function send() {
    setError(null)
    const requested = code.trim().toUpperCase()
    const result = await submit.run(() => joinRequests.create(requested))
    if (result) {
      // Le code soumis est mémorisé : la confirmation doit le citer.
      setSentCode(requested)
      setCode('')
      mine.reload()
    } else {
      const message = firstFieldError(submit.error, 'code', t('error.joinDisabled'))
      setError(message)
    }
  }

  const steps = [t('join.how1'), t('join.how2'), t('join.how3'), t('join.how4')]

  return (
    <div className="max-w-lg">
      <PageHeader title={t('join.title')} subtitle={t('join.subtitle')}/>

      {sentCode ? (
        <Card className="p-6 text-center mb-5">
          <div className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3" style={{ background: 'rgba(232,130,12,0.12)', color: 'var(--accent)' }}>
            <Icons.Clock/>
          </div>
          <h2 className="font-semibold mb-1">{t('join.pending.title')}</h2>
          <p className="text-sm text-[var(--muted-foreground)]">{t('join.pending.body', { code: sentCode })}</p>
          <div className="mt-4"><Badge label={t('join.pending.badge')} color="orange"/></div>
        </Card>
      ) : (
        <Card className="p-5 mb-5">
          {error && <div className="mb-4"><Alert message={error} type="error"/></div>}
          <label className="text-sm font-medium block mb-1.5">{t('join.codeLabel')}</label>
          <input
            value={code}
            onChange={event => setCode(event.target.value.toUpperCase())}
            placeholder={t('join.codePlaceholder')}
            maxLength={16}
            className="w-full px-3.5 py-2.5 text-sm font-mono border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          <div className="mt-4">
            <Btn full onClick={() => void send()} disabled={submit.pending || code.trim().length < 4}>
              {submit.pending ? t('join.checking') : t('join.submit')}
            </Btn>
          </div>
        </Card>
      )}

      <Card className="p-5 mb-5">
        <p className="text-sm font-medium mb-3">{t('join.how.title')}</p>
        <div className="space-y-2.5">
          {steps.map((step, index) => (
            <div key={step} className="flex items-start gap-2.5">
              <span
                className="w-4.5 h-4.5 w-5 h-5 rounded-full text-[10px] font-bold flex items-center justify-center shrink-0 mt-0.5"
                style={{ background: 'var(--primary)', color: 'white' }}
              >
                {index + 1}
              </span>
              <p className="text-xs text-[var(--muted-foreground)]">{step}</p>
            </div>
          ))}
        </div>
      </Card>

      <h2 className="font-display text-lg font-semibold mb-3">{t('join.myRequests')}</h2>
      {mine.loading ? (
        <SkeletonList rows={2} className="h-14"/>
      ) : (mine.data?.data.length ?? 0) === 0 ? (
        <EmptyState message={t('join.noRequests')}/>
      ) : (
        <Card className="divide-y divide-[var(--border)]">
          {(mine.data?.data ?? []).map(request => (
            <div key={request.id} className="p-4 flex items-center gap-3">
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{request.classroom?.name ?? '—'}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{request.classroom?.teacher_name ?? ''}</p>
              </div>
              <Badge
                label={t(
                  request.status === 'accepted'
                    ? 'join.status.accepted'
                    : request.status === 'rejected'
                      ? 'join.status.rejected'
                      : 'join.status.pending',
                )}
                color={request.status === 'accepted' ? 'green' : request.status === 'rejected' ? 'red' : 'orange'}
              />
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}
