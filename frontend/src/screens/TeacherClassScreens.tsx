import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useI18n } from '../i18n'
import { ApiError, errorMessage, firstFieldError } from '../lib/api'
import { ai, assignments, classrooms, content, flashcards, joinRequests, quizzes } from '../lib/endpoints'
import { useAction, useAsync } from '../lib/useAsync'
import { EditButton } from '../components/EditButton'
import { CopyButton } from '../components/CopyButton'
import type { ApiAiJob, ApiQuiz, ApiQuizResults, ApiSubmission, Locale } from '../lib/types'
import {
  Alert,
  AsyncBoundary,
  Badge,
  Btn,
  ConfirmButton,
  Card,
  EmptyState,
  Icons,
  Input,
  PageHeader,
  ProgressBar,
  Select,
  SkeletonList,
  StatTile,
  Tabs,
  Textarea,
  Toggle,
} from '../components/UI'

type TabId =
  | 'overview'
  | 'requests'
  | 'members'
  | 'announcements'
  | 'materials'
  | 'quizzes'
  | 'assignments'
  | 'settings'

/* ══ Gestion d'une classe ═══════════════════════════════════════════ */

export function TeacherClassScreen() {
  const { id } = useParams()
  const classroomId = Number(id)
  const { t, formatDate, formatDateTime, locale } = useI18n()
  const [search] = useSearchParams()
  const [tab, setTab] = useState<TabId>((search.get('tab') as TabId) ?? 'overview')
  const [notice, setNotice] = useState<string | null>(null)

  const detail = useAsync(signal => classrooms.show(classroomId, { signal }), [classroomId])
  const members = useAsync(signal => classrooms.members(classroomId, { signal }), [classroomId])
  const pending = useAsync(signal => classrooms.joinRequests(classroomId, { signal }), [classroomId])
  const announcements = useAsync(signal => content.announcements(classroomId, { signal }), [classroomId])
  const materials = useAsync(signal => content.materials(classroomId, { signal }), [classroomId])
  const quizList = useAsync(signal => quizzes.listForTeacher(classroomId, { signal }), [classroomId])
  const homework = useAsync(signal => assignments.list(classroomId, { signal }), [classroomId])
  const decks = useAsync(signal => flashcards.list(classroomId, { signal }), [classroomId])

  const readOnly = detail.data?.is_read_only ?? false
  const accepted = (members.data?.data ?? []).filter(item => item.status === 'accepted')
  const publishedQuizzes = (quizList.data?.data ?? []).filter(item => item.status === 'published')

  const refreshAll = () => {
    detail.reload()
    members.reload()
    pending.reload()
    announcements.reload()
    materials.reload()
    quizList.reload()
    homework.reload()
    decks.reload()
  }

  const tabs = [
    { id: 'overview' as const, label: t('manage.tab.overview') },
    { id: 'requests' as const, label: `${t('manage.tab.requests')}${(pending.data?.data.length ?? 0) > 0 ? ` (${pending.data?.data.length})` : ''}` },
    { id: 'members' as const, label: t('manage.tab.members') },
    { id: 'announcements' as const, label: t('manage.tab.announcements') },
    { id: 'materials' as const, label: t('manage.tab.materials') },
    { id: 'quizzes' as const, label: t('manage.tab.quizzes') },
    { id: 'assignments' as const, label: t('manage.tab.assignments') },
    { id: 'settings' as const, label: t('manage.tab.settings') },
  ]

  if (detail.error) {
    return <Alert message={errorMessage(detail.error, t('error.notMember'))} type="error"/>
  }

  return (
    <div>
      <PageHeader
        title={detail.data?.name ?? t('common.loading')}
        subtitle={detail.data ? `${detail.data.subject} · ${detail.data.group_label} · ${detail.data.school_year}` : undefined}
        back={() => window.history.back()}
        actions={
          <Link to="/app">
            <Btn size="sm" variant="ghost">{t('createClass.dashboard')}</Btn>
          </Link>
        }
      />

      {readOnly && <div className="mb-4"><Alert message={t('manage.readOnly')} type="warning"/></div>}
      {notice && <div className="mb-4"><Alert message={notice} type="success"/></div>}

      <Tabs tabs={tabs} active={tab} onChange={value => setTab(value as TabId)}/>

      <div className="mt-5">
        {tab === 'overview' && (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <StatTile label={t('manage.stat.members')} value={String(accepted.length)}/>
            <StatTile label={t('manage.stat.pending')} value={String(pending.data?.data.length ?? 0)} color="var(--accent)"/>
            <StatTile label={t('manage.stat.quizzes')} value={String(publishedQuizzes.length)} color="green"/>
            <StatTile label={t('manage.stat.assignments')} value={String(homework.data?.data.length ?? 0)} color="var(--primary)"/>
          </div>
        )}

        {tab === 'requests' && (
          <RequestsTab
            classroomId={classroomId}
            items={pending.data?.data ?? []}
            loading={pending.loading}
            error={pending.error}
            onReload={() => { pending.reload(); setNotice(t('common.saved')) }}
          />
        )}

        {tab === 'members' && (
          <MembersTab
            classroomId={classroomId}
            items={members.data?.data ?? []}
            loading={members.loading}
            error={members.error}
            onReload={members.reload}
            formatDate={formatDate}
            readOnly={readOnly}
          />
        )}

        {tab === 'announcements' && (
          <AnnouncementTab
            classroomId={classroomId}
            items={announcements.data?.data ?? []}
            loading={announcements.loading}
            error={announcements.error}
            onReload={() => { announcements.reload(); setNotice(t('common.saved')) }}
            formatDate={formatDate}
            readOnly={readOnly}
          />
        )}

        {tab === 'materials' && (
          <MaterialsTab
            classroomId={classroomId}
            items={materials.data?.data ?? []}
            loading={materials.loading}
            error={materials.error}
            onReload={materials.reload}
            readOnly={readOnly}
          />
        )}

        {tab === 'quizzes' && (
          <QuizzesTab
            classroomId={classroomId}
            items={quizList.data?.data ?? []}
            loading={quizList.loading}
            error={quizList.error}
            onReload={() => { quizList.reload(); decks.reload(); setNotice(t('common.saved')) }}
            readOnly={readOnly}
            locale={locale}
          />
        )}

        {tab === 'assignments' && (
          <AssignmentsTab
            classroomId={classroomId}
            items={homework.data?.data ?? []}
            loading={homework.loading}
            error={homework.error}
            onReload={homework.reload}
            readOnly={readOnly}
            formatDateTime={formatDateTime}
          />
        )}

        {tab === 'settings' && (
          <SettingsTab
            classroomId={classroomId}
            joinCode={detail.data?.join_code ?? null}
            classroom={detail.data}
            joinEnabled={detail.data?.join_enabled ?? true}
            readOnly={readOnly}
            onChanged={refreshAll}
          />
        )}
      </div>
    </div>
  )
}

/* ══ Onglet demandes ════════════════════════════════════════════════ */

function RequestsTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
}: {
  classroomId: number
  items: import('../lib/types').ApiMember[]
  loading: boolean
  error: Error | null
  onReload: () => void
}) {
  const { t } = useI18n()
  const action = useAction()

  async function decide(membershipId: number, status: 'accept' | 'reject') {
    await action.run(async () => {
      try { await (status === 'accept' ? joinRequests.accept(membershipId) : joinRequests.reject(membershipId)) }
      finally { onReload() }
    })
  }

  async function acceptAll() {
    await action.run(async () => { await classrooms.acceptAll(classroomId); onReload() })
  }

  return (
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onReload}
      errorMessage={t('common.error')}
      isEmpty={items.length === 0}
      empty={<EmptyState message={t('manage.noRequests')}/>}
    >
      {action.error && <Alert type="error" message={errorMessage(action.error, t('error.unknown'))}/>}
      <div className="flex justify-end mb-3">
        <ConfirmButton size="sm" variant="success" onClick={() => void acceptAll()} disabled={action.pending}>
          {t('manage.accepted')} · {items.length}
        </ConfirmButton>
      </div>

      <Card className="divide-y divide-[var(--border)]">
        {items.map(member => (
          <div key={member.membership_id} className="p-4 flex items-center gap-3">
            <div className="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold" style={{ background: 'var(--accent)', color: 'white' }}>
              {member.user?.initials ?? '—'}
            </div>
            <p className="text-sm flex-1">{member.user?.display_name ?? '—'}</p>
            <div className="flex gap-1.5">
              <Btn size="sm" variant="success" onClick={() => void decide(member.membership_id, 'accept')} disabled={action.pending}>
                {t('partners.accept')}
              </Btn>
              <Btn size="sm" variant="ghost" onClick={() => void decide(member.membership_id, 'reject')} disabled={action.pending}>
                {t('partners.reject')}
              </Btn>
            </div>
          </div>
        ))}
      </Card>
    </AsyncBoundary>
  )
}

/* ══ Onglet membres ═════════════════════════════════════════════════ */

function MembersTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
  formatDate,
  readOnly,
}: {
  classroomId: number
  items: import('../lib/types').ApiMember[]
  loading: boolean
  error: Error | null
  onReload: () => void
  formatDate: (value: string | null) => string
  readOnly: boolean
}) {
  const { t } = useI18n()
  const action = useAction()
  const accepted = items.filter(item => item.status === 'accepted')
  const [file, setFile] = useState<File | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [importResult, setImportResult] = useState<import('../lib/types').RosterImportResult | null>(null)

  async function remove(membershipId: number) {
    await action.run(async () => { await classrooms.removeMember(classroomId, membershipId); onReload() })
  }

  return (
    <div>
      {!readOnly && <Card className="p-4 mb-4 space-y-3">
        <p className="text-sm">{t('roster.hint')}</p>
        <input aria-label={t('roster.import')} type="file" accept=".csv,.txt" onChange={event => setFile(event.target.files?.[0] ?? null)}/>
        <ConfirmButton disabled={!file || file.size > 2 * 1024 * 1024 || action.pending} onClick={() => void action.run(async () => {
          const result = await classrooms.importMembers(classroomId, file!)
          setNotice(t('roster.result', { count: result.imported })); setImportResult(result); onReload()
        })}>{t('roster.import')}</ConfirmButton>
      </Card>}
      {notice && <Alert message={notice} type="success"/>}
      {importResult?.errors.map(item => <Alert key={item.row} type="warning" message={t('roster.rowError', { row: item.row, reason: t(`roster.${item.reason}`) })}/>)}
      {action.error && <Alert message={errorMessage(action.error, t('error.unknown'))} type="error"/>}
    <AsyncBoundary
      loading={loading}
      error={error}
      onRetry={onReload}
      errorMessage={t('common.error')}
      isEmpty={accepted.length === 0}
      empty={<EmptyState message={t('manage.noMembers')}/>}
    >
      <Card className="divide-y divide-[var(--border)]">
        {accepted.map(member => (
          <div key={member.membership_id} className="p-4 flex items-center gap-3">
            <div className="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold" style={{ background: 'var(--secondary)', color: 'var(--primary)' }}>
              {member.user?.initials ?? '—'}
            </div>
            <div className="flex-1 min-w-0">
              {/* RG-18 : aucun email d'étudiant n'est transmis au client. */}
              <p className="text-sm">{member.user?.display_name ?? '—'}</p>
              <p className="text-xs text-[var(--muted-foreground)]">
                {t('manage.joinedOn', { date: formatDate(member.requested_at ?? member.decided_at) })}
              </p>
            </div>
            {!readOnly && (
              <ConfirmButton size="sm" variant="ghost" onClick={() => { if (member.user) void remove(member.user.id) }} disabled={action.pending || !member.user}>
                {t('manage.remove')}
              </ConfirmButton>
            )}
          </div>
        ))}
      </Card>
    </AsyncBoundary>
    </div>
  )
}

/* ══ Onglet annonces ════════════════════════════════════════════════ */

function AnnouncementTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
  formatDate,
  readOnly,
}: {
  classroomId: number
  items: import('../lib/types').ApiAnnouncement[]
  loading: boolean
  error: Error | null
  onReload: () => void
  formatDate: (value: string | null) => string
  readOnly: boolean
}) {
  const { t, locale } = useI18n()
  const [form, setForm] = useState({ title: '', body: '', pinned: false })
  const create = useAction()
  const remove = useAction()

  async function submit() {
    const result = await create.run(() => content.createAnnouncement(classroomId, form, { locale }))
    if (result) {
      setForm({ title: '', body: '', pinned: false })
      onReload()
    }
  }

  return (
    <div className="grid lg:grid-cols-2 gap-5">
      {!readOnly && (
        <Card className="p-5 h-fit space-y-3">
          <p className="text-sm font-semibold">{t('manage.newAnnouncement')}</p>
          {create.error && <Alert message={errorMessage(create.error, t('error.unknown'))} type="error"/>}
          <Input
            label={t('publish.title')}
            placeholder={t('publish.titleAnnouncementPlaceholder')}
            value={form.title}
            onChange={value => setForm({ ...form, title: value })}
          />
          <Textarea
            label={t('publish.body')}
            placeholder={t('publish.bodyPlaceholder')}
            value={form.body}
            onChange={value => setForm({ ...form, body: value })}
          />
          <Toggle checked={form.pinned} onChange={value => setForm({ ...form, pinned: value })} label={t('publish.pin')}/>
          <p className="text-xs text-[var(--muted-foreground)]">{t('publish.pinHint')}</p>
          <Btn full onClick={() => void submit()} disabled={create.pending || !form.title || !form.body}>
            {t('publish.submit')}
          </Btn>
        </Card>
      )}

      <div>
        <AsyncBoundary
          loading={loading}
          error={error}
          onRetry={onReload}
          errorMessage={t('common.error')}
          isEmpty={items.length === 0}
          empty={<EmptyState message={t('class.noAnnouncements')}/>}
        >
          <div className="space-y-3">
            {items.map(item => (
              <Card key={item.id} className="p-4">
                <div className="flex items-center gap-2 mb-1.5">
                  {item.pinned && <Badge label={t('class.pinned')} color="orange"/>}
                  <h3 className="font-semibold text-sm flex-1">{item.title}</h3>
                  {!readOnly && <EditButton initial={{ title: item.title, body: item.body, pinned: item.pinned }} labels={{ title: t('common.title'), body: t('publish.body'), pinned: t('publish.pin') }} save={values => content.updateAnnouncement(item.id, values, { locale })} onSaved={onReload}/>}
                  {!readOnly && (
                    <ConfirmButton variant="ghost" size="sm"
                      onClick={() => void remove.run(async () => { await content.deleteAnnouncement(item.id, { locale }); onReload() })}
                      disabled={remove.pending}
                      className="text-[var(--muted-foreground)] hover:text-[var(--danger)]"
                      aria-label={t('common.delete')}
                    >
                      <Icons.Trash/>
                    </ConfirmButton>
                  )}
                </div>
                <p className="text-sm text-[var(--muted-foreground)] whitespace-pre-wrap mb-2">{item.body}</p>
                <p className="text-xs text-[var(--muted-foreground)]">{formatDate(item.created_at)}</p>
              </Card>
            ))}
          </div>
        </AsyncBoundary>
      </div>
    </div>
  )
}

/* ══ Onglet ressources ══════════════════════════════════════════════ */

const MAX_UPLOAD_MB = 10

function MaterialsTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
  readOnly,
}: {
  classroomId: number
  items: import('../lib/types').ApiMaterial[]
  loading: boolean
  error: Error | null
  onReload: () => void
  readOnly: boolean
}) {
  const { t, locale, formatNumber } = useI18n()
  const [type, setType] = useState<'file' | 'link'>('file')
  const [title, setTitle] = useState('')
  const [chapter, setChapter] = useState('')
  const [url, setUrl] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const publish = useAction()
  const remove = useAction()

  async function submit() {
    setFieldError(null)
    const form = new FormData()
    form.append('title', title)
    if (chapter) form.append('chapter', chapter)
    form.append('type', type)
    if (type === 'link') form.append('url', url)
    if (type === 'file' && file) form.append('file', file)

    const result = await publish.run(() => content.createMaterialForm(classroomId, form, { locale }))
    if (result) {
      setTitle('')
      setChapter('')
      setUrl('')
      setFile(null)
      onReload()
    } else {
      setFieldError(firstFieldError(publish.error, 'file', t('error.unknown')))
    }
  }

  return (
    <div className="grid lg:grid-cols-2 gap-5">
      {!readOnly && (
        <Card className="p-5 h-fit space-y-3">
          <p className="text-sm font-semibold">{t('manage.newMaterial')}</p>
          {publish.error && <Alert message={errorMessage(publish.error, t('error.unknown'))} type="error"/>}

          <Select
            label={t('publish.type')}
            value={type}
            onChange={value => setType(value as 'file' | 'link')}
            options={[
              { value: 'file', label: t('publish.type.file') },
              { value: 'link', label: t('publish.type.link') },
            ]}
          />
          <Input label={t('publish.title')} placeholder={t('publish.titleMaterialPlaceholder')} value={title} onChange={setTitle}/>
          <Input label={t('publish.chapter')} placeholder={t('publish.chapterPlaceholder')} value={chapter} onChange={setChapter}/>

          {type === 'link' ? (
            <Input label={t('publish.url')} value={url} onChange={setUrl} placeholder="https://"/>
          ) : (
            <div>
              <label className="text-sm font-medium block mb-1.5">{t('publish.drop')}</label>
              <label className="flex flex-col items-center justify-center gap-2 px-4 py-6 border-2 border-dashed border-[var(--border)] rounded-lg cursor-pointer hover:border-[var(--primary)] transition-colors">
                <Icons.Upload/>
                <span className="text-xs text-[var(--muted-foreground)]">{t('publish.dropHint')}</span>
                <input
                  type="file"
                  aria-label={t('publish.drop')}
                  className="hidden"
                  onChange={event => {
                    const selected = event.target.files?.[0] ?? null
                    setFile(selected)
                    setFieldError(null)
                    if (selected) {
                      if (selected.size > MAX_UPLOAD_MB * 1024 * 1024) {
                        setFile(null)
                        setFieldError(t('error.fileTooLarge'))
                        return
                      }
                      if (!title) setTitle(selected.name.replace(/\.[^.]+$/, ''))
                    }
                  }}
                />
                {file && (
                  <span className="text-xs font-medium">
                    {file.name} · {t('common.kilobytes', { count: formatNumber(Math.round(file.size / 1024)) })}
                  </span>
                )}
              </label>
              {fieldError && <p className="text-xs text-[var(--danger)] mt-1">{fieldError}</p>}
            </div>
          )}

          <Btn
            full
            onClick={() => void submit()}
            disabled={publish.pending || !title || (type === 'link' ? !url : !file)}
          >
            {t('publish.submit')}
          </Btn>
        </Card>
      )}

      <div>
        {remove.error && <Alert type="error" message={errorMessage(remove.error, t('error.unknown'))}/>}
        <AsyncBoundary
          loading={loading}
          error={error}
          onRetry={onReload}
          errorMessage={t('common.error')}
          isEmpty={items.length === 0}
          empty={<EmptyState message={t('class.noMaterials')}/>}
        >
          <div className="space-y-2.5">
            {items.map(item => (
              <Card key={item.id} className="p-4 flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style={{ background: 'var(--secondary)', color: 'var(--primary)' }}>
                  {item.type === 'link' ? <Icons.External/> : <Icons.Book/>}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{item.title}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {item.chapter ?? '—'}
                    {item.file_size ? ` · ${formatNumber(item.file_size)} o` : ''}
                  </p>
                </div>
                {item.has_file && (
                  <Btn
                    size="sm"
                    variant="ghost"
                    onClick={() => void content.downloadMaterial(item.id, item.file_name ?? `ressource-${item.id}`, { locale })}
                  >
                    {t('common.download')}
                  </Btn>
                )}
                {!readOnly && <EditButton initial={{ title: item.title, chapter: item.chapter ?? '' }} labels={{ title: t('publish.title'), chapter: t('publish.chapter') }} save={values => content.updateMaterial(item.id, values, { locale })} onSaved={onReload}/>}
                {!readOnly && (
                  <ConfirmButton variant="ghost" size="sm"
                    onClick={() => void remove.run(async () => { await content.deleteMaterial(item.id, { locale }); onReload() })}
                    disabled={remove.pending}
                    className="text-[var(--muted-foreground)] hover:text-[var(--danger)]"
                    aria-label={t('common.delete')}
                  >
                    <Icons.Trash/>
                  </ConfirmButton>
                )}
              </Card>
            ))}
          </div>
        </AsyncBoundary>
      </div>
    </div>
  )
}

/* ══ Onglet quiz (et flashcards IA) ══════════════════════════════════ */

function QuizzesTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
  readOnly,
  locale,
}: {
  classroomId: number
  /** Le gestionnaire reçoit `QuizResource` complet (source, reviewed, can_publish). */
  items: ApiQuiz[]
  loading: boolean
  error: Error | null
  onReload: () => void
  readOnly: boolean
  locale: Locale
}) {
  const { t } = useI18n()
  const [aiError, setAiError] = useState<string | null>(null)
  const [job, setJob] = useState<ApiAiJob | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [target, setTarget] = useState<'quiz' | 'flashcard'>('quiz')
  const [deckTitle, setDeckTitle] = useState('')
  const [cards, setCards] = useState([{ front: '', back: '' }])
  const createDeck = useAction()
  const generate = useAction()
  const publishQuiz = useAction()
  const publishDeck = useAction()
  const decks = useAsync(signal => flashcards.list(classroomId, { signal }), [classroomId])

  /* F-IA-02 : la tâche est asynchrone, l'UI suit son état sans bloquer. */
  useEffect(() => {
    if (!job || (job.status !== 'queued' && job.status !== 'processing')) return
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      try {
        const next = await ai.job(job.id, { locale, signal: controller.signal })
        if (!controller.signal.aborted) setJob(next)
      } catch (cause) {
        if (!controller.signal.aborted) setAiError(errorMessage(cause, t('error.unknown')))
      }
    }, 3000)
    return () => { clearTimeout(timer); controller.abort() }
  }, [job, locale, onReload])

  const terminalJob = useRef<number | null>(null)
  useEffect(() => {
    if (job && (job.status === 'done' || job.status === 'failed') && terminalJob.current !== job.id) {
      terminalJob.current = job.id
      onReload()
      decks.reload()
    }
  }, [job, onReload, decks.reload])

  async function startGeneration() {
    if (!file) return
    setAiError(null)
    const result = await generate.run(async () => {
      try { return await ai.generate(classroomId, file, target) }
      catch (cause) { setAiError(cause instanceof ApiError && cause.status === 429 ? t('aiQuiz.quota') : errorMessage(cause, t('error.pdfOnly'))); throw cause }
    })
    if (result) {
      setJob(result as ApiAiJob)
    }
  }

  return (
    <div className="space-y-6">
      {publishQuiz.error && <Alert type="error" message={errorMessage(publishQuiz.error, t('error.unknown'))}/>}
      {publishDeck.error && <Alert type="error" message={errorMessage(publishDeck.error, t('error.unknown'))}/>}
      {!readOnly && <Card className="p-5 space-y-3">
        <h3 className="text-sm font-semibold">{t('flashcards.subtitle')}</h3>
        <Input label={t('publish.title')} value={deckTitle} onChange={setDeckTitle}/>
        {cards.map((card, index) => <div key={index} className="grid sm:grid-cols-2 gap-3">
          <Input label={t('createQuiz.statement')} value={card.front} onChange={value => setCards(current => current.map((item, i) => i === index ? { ...item, front: value } : item))}/>
          <Input label={t('aiQuiz.answer')} value={card.back} onChange={value => setCards(current => current.map((item, i) => i === index ? { ...item, back: value } : item))}/>
        </div>)}
        {createDeck.error && <Alert type="error" message={errorMessage(createDeck.error, t('error.unknown'))}/>}
        <Btn variant="secondary" onClick={() => setCards(current => [...current, { front: '', back: '' }])}>{t('createQuiz.addQuestion')}</Btn>
        <Btn disabled={createDeck.pending || !deckTitle.trim() || cards.some(card => !card.front.trim() || !card.back.trim())} onClick={() => void createDeck.run(async () => { await flashcards.create(classroomId, { title: deckTitle, cards }); setDeckTitle(''); setCards([{ front: '', back: '' }]); decks.reload(); onReload() })}>{t('common.save')}</Btn>
      </Card>}
      {!readOnly && (
        <Card className="p-5">
          <p className="text-sm font-semibold mb-3">{t('manage.aiGenerate')}</p>

          {aiError && <div className="mb-3"><Alert message={aiError} type="error"/></div>}
          {(aiError || job?.status === 'failed') && <Link to={`/app/classes/${classroomId}/manage/quizzes/new`} className="inline-flex min-h-11 items-center text-[var(--primary)] mb-3">{t('aiQuiz.manualFallback')}</Link>}

          {job === null ? (
            <>
              <div className="flex flex-col sm:flex-row gap-3 mb-3">
                <Select
                  value={target}
                  onChange={value => setTarget(value as 'quiz' | 'flashcard')}
                  options={[
                    { value: 'quiz', label: t('nav.quiz') },
                    { value: 'flashcard', label: t('flashcards.subtitle') },
                  ]}
                />
              </div>

              <label className="flex flex-col items-center justify-center gap-2 px-4 py-6 border-2 border-dashed border-[var(--border)] rounded-lg cursor-pointer hover:border-[var(--primary)] transition-colors mb-3">
                <Icons.Upload/>
                <span className="text-xs text-[var(--muted-foreground)]">{t('aiQuiz.dropHint')}</span>
                <input
                  type="file"
                  accept="application/pdf"
                  aria-label={t('aiQuiz.dropHint')}
                  className="hidden"
                  onChange={event => setFile(event.target.files?.[0] ?? null)}
                />
                {file && <span className="text-xs font-medium">{file.name}</span>}
              </label>

              <Btn full onClick={() => void startGeneration()} disabled={!file || generate.pending}>
                {generate.pending ? t('aiQuiz.running') : t('aiQuiz.start')}
              </Btn>
            </>
          ) : job.status === 'failed' ? (
            <div className="flex items-center justify-between gap-3">
              <Alert message={t('aiQuiz.failed')} type="error"/>
              <Btn size="sm" variant="secondary" onClick={() => setJob(null)}>{t('common.retry')}</Btn>
            </div>
          ) : job.status === 'done' ? (
            <Alert
              message={
                job.target === 'quiz'
                  ? t('status.done')
                  : t('aiQuiz.generatedDraft')
              }
              type="success"
            />
          ) : (
            <div>
              <p className="text-sm font-medium mb-1">{t('aiQuiz.running')}</p>
              <p className="text-xs text-[var(--muted-foreground)] mb-3">{t('aiQuiz.runningBody')}</p>
              <ProgressBar value={job.status === 'processing' ? 60 : 20}/>
            </div>
          )}
        </Card>
      )}

      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-semibold text-sm">{t('manage.tab.quizzes')}</h3>
          {!readOnly && (
            <Link to={`/app/classes/${classroomId}/manage/quizzes/new`}>
              <Btn size="sm"><Icons.Plus/> {t('manage.createManual')}</Btn>
            </Link>
          )}
        </div>

        <AsyncBoundary
          loading={loading}
          error={error}
          onRetry={onReload}
          errorMessage={t('common.error')}
          isEmpty={items.length === 0}
          empty={<EmptyState message={t('class.noQuizzes')}/>}
        >
          <div className="space-y-2.5">
            {items.map(quiz => (
              <Card key={quiz.id} className="p-4 flex items-center gap-3">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-medium truncate">{quiz.title}</p>
                    <Badge
                      label={t(quiz.status === 'published' ? 'quiz.status.published' : 'quiz.status.draft')}
                      color={quiz.status === 'published' ? 'green' : 'default'}
                    />
                    {quiz.source === 'ai' && (
                      <Badge
                        label={quiz.reviewed ? t('aiQuiz.reviewed') : t('aiQuiz.needsReview')}
                        color={quiz.reviewed ? 'blue' : 'orange'}
                      />
                    )}
                  </div>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {t('student.questions', { count: quiz.questions_count })}
                    {quiz.time_limit_min ? ` · ${t('quiz.minutes', { count: quiz.time_limit_min })}` : ''}
                  </p>
                </div>

                <div className="flex gap-1.5">
                  {quiz.status === 'draft' && !readOnly && <Link to={`/app/classes/${classroomId}/manage/quizzes/${quiz.id}/edit`}><Btn size="sm" variant="secondary">{t('common.edit')}</Btn></Link>}
                  <Link to={`/app/classes/${classroomId}/manage/quizzes/${quiz.id}/results`}>
                    <Btn size="sm" variant="ghost">{t('progress.title')}</Btn>
                  </Link>
                  {quiz.source === 'ai' && !quiz.reviewed && !readOnly && (
                    <Btn
                      size="sm"
                      variant="secondary"
                      onClick={() => { window.location.href = `/app/classes/${classroomId}/manage/quizzes/${quiz.id}/edit` }}
                    >
                      {t('aiQuiz.review')}
                    </Btn>
                  )}
                  {quiz.status === 'draft' && !readOnly && (
                    <Btn
                      size="sm"
                      onClick={() => void publishQuiz.run(() => quizzes.publish(quiz.id, { locale })).then(onReload)}
                      disabled={publishQuiz.pending || !quiz.can_publish}
                      title={quiz.can_publish ? undefined : t('aiQuiz.needsReview')}
                    >
                      {t('createQuiz.publish')}
                    </Btn>
                  )}
                  {!readOnly && (
                    <ConfirmButton variant="ghost" size="sm"
                      onClick={() => void publishQuiz.run(async () => { await quizzes.destroy(quiz.id, { locale }); onReload() })}
                      disabled={publishQuiz.pending}
                      className="text-[var(--muted-foreground)] hover:text-[var(--danger)] px-1"
                      aria-label={t('common.delete')}
                    >
                      <Icons.Trash/>
                    </ConfirmButton>
                  )}
                </div>
              </Card>
            ))}
          </div>
        </AsyncBoundary>
      </div>

      {(decks.data?.data.length ?? 0) > 0 && (
        <div>
          <h3 className="font-semibold text-sm mb-3">{t('flashcards.subtitle')}</h3>
          <div className="space-y-2.5">
            {(decks.data?.data ?? []).map(deck => (
              <Card key={deck.id} className="p-4 flex items-center gap-3">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-medium truncate">{deck.title}</p>
                    <Badge
                      label={t(deck.status === 'published' ? 'quiz.status.published' : 'quiz.status.draft')}
                      color={deck.status === 'published' ? 'green' : 'default'}
                    />
                  </div>
                  <p className="text-xs text-[var(--muted-foreground)]">{t('student.questions', { count: deck.cards_count })}</p>
                </div>
                <Link to={`/app/flashcard-decks/${deck.id}`}><Btn size="sm" variant="secondary">{t('aiQuiz.review')}</Btn></Link>
                {!readOnly && <ConfirmButton size="sm" variant="danger" disabled={publishDeck.pending} onClick={() => void publishDeck.run(async () => { await flashcards.destroy(deck.id, { locale }); onReload(); decks.reload() })}>{t('common.delete')}</ConfirmButton>}
                {deck.status === 'draft' && !readOnly && (
                  <Btn
                    size="sm"
                    disabled={!deck.reviewed || publishDeck.pending}
                    title={deck.reviewed ? undefined : t('aiQuiz.needsReview')}
                    onClick={() => void publishDeck.run(() => flashcards.publish(deck.id, { locale })).then(onReload)}
                  >
                    {t('createQuiz.publish')}
                  </Btn>
                )}
              </Card>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

/* ══ Onglet devoirs ═════════════════════════════════════════════════ */

function AssignmentsTab({
  classroomId,
  items,
  loading,
  error,
  onReload,
  readOnly,
  formatDateTime,
}: {
  classroomId: number
  items: import('../lib/types').ApiAssignment[]
  loading: boolean
  error: Error | null
  onReload: () => void
  readOnly: boolean
  formatDateTime: (value: string | null) => string
}) {
  const { t, locale } = useI18n()
  const [title, setTitle] = useState('')
  const [instructions, setInstructions] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [creating, setCreating] = useState(false)
  const create = useAction()
  const remove = useAction()

  async function submit() {
    const result = await create.run(() =>
      assignments.create(
        classroomId,
        {
          title,
          instructions: instructions || null,
          // L'API attend une date ISO complète ; le contrôle date donne déjà 16:00.
          due_at: dueAt ? `${dueAt}T23:59:59` : null,
        },
        { locale },
      ),
    )
    if (result) {
      setTitle('')
      setInstructions('')
      setDueAt('')
      setCreating(false)
      onReload()
    }
  }

  return (
    <div className="grid lg:grid-cols-2 gap-5">
      {!readOnly && (
        <Card className="p-5 h-fit space-y-3">
          <p className="text-sm font-semibold">
            {creating ? t('common.close') : t('manage.tab.assignments')}
          </p>
          {create.error && <Alert message={errorMessage(create.error, t('error.unknown'))} type="error"/>}

          {creating ? (
            <>
              <Input label={t('publish.title')} value={title} onChange={setTitle}/>
              <Textarea label={t('publish.body')} value={instructions} onChange={setInstructions}/>
              <Input label={t('manage.dueDate', { date: '' })} type="date" value={dueAt} onChange={setDueAt}/>
              <div className="flex gap-2 justify-end">
                <Btn variant="ghost" onClick={() => setCreating(false)}>{t('common.cancel')}</Btn>
                <Btn onClick={() => void submit()} disabled={create.pending || !title}>{t('publish.submit')}</Btn>
              </div>
            </>
          ) : (
            <Btn full onClick={() => setCreating(true)}><Icons.Plus/> {t('manage.tab.assignments')}</Btn>
          )}
        </Card>
      )}

      <div>
        <AsyncBoundary
          loading={loading}
          error={error}
          onRetry={onReload}
          errorMessage={t('common.error')}
          isEmpty={items.length === 0}
          empty={<EmptyState message={t('grade.noAssignments')}/>}
        >
          <div className="space-y-2.5">
            {items.map(item => (
              <Card key={item.id} className="p-4 flex items-center gap-3">
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{item.title}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {item.due_at ? formatDateTime(item.due_at) : '—'}
                    {item.submissions_count ? ` · ${t('manage.submittedCount', { submitted: item.submissions_count, total: 0 })}` : ''}
                  </p>
                </div>
                {item.is_overdue && <Badge label={t('deadlines.overdue')} color="red"/>}
                {!readOnly && <EditButton initial={{ title: item.title, instructions: item.instructions ?? '', due_at: item.due_at ?? '' }} labels={{ title: t('publish.title'), instructions: t('assignment.instructions'), due_at: t('assignment.dueAt') }} save={values => assignments.update(item.id, { ...values, due_at: values.due_at || null }, { locale })} onSaved={onReload}/>}
                <Link to={`/app/classes/${classroomId}/manage/assignments/${item.id}/grade`}>
                  <Btn size="sm" variant="secondary">{t('manage.grade')}</Btn>
                </Link>
                {!readOnly && (
                  <ConfirmButton variant="ghost" size="sm"
                    onClick={() => void remove.run(async () => { await assignments.destroy(item.id, { locale }); onReload() })}
                    disabled={remove.pending}
                    className="text-[var(--muted-foreground)] hover:text-[var(--danger)]"
                    aria-label={t('common.delete')}
                  >
                    <Icons.Trash/>
                  </ConfirmButton>
                )}
              </Card>
            ))}
          </div>
        </AsyncBoundary>
      </div>
    </div>
  )
}

/* ══ Onglet paramètres ══════════════════════════════════════════════ */

function SettingsTab({
  classroomId,
  classroom,
  joinCode,
  joinEnabled,
  readOnly,
  onChanged,
}: {
  classroomId: number
  classroom: import('../lib/types').ApiClassroom | null
  joinCode: string | null
  joinEnabled: boolean
  readOnly: boolean
  onChanged: () => void
}) {
  const { t, locale } = useI18n()
  const [code, setCode] = useState(joinCode ?? '')
  const [enabled, setEnabled] = useState(joinEnabled)
  const [confirmArchive, setConfirmArchive] = useState(false)
  const regenerate = useAction()
  const toggle = useAction()
  const archive = useAction()

  useEffect(() => {
    setCode(joinCode ?? '')
    setEnabled(joinEnabled)
  }, [joinCode, joinEnabled])

  return (
    <div className="max-w-lg space-y-4">
      {classroom && !readOnly && <EditButton initial={{ name: classroom.name, subject: classroom.subject, group_label: classroom.group_label, school_year: classroom.school_year }} labels={{ name: t('createClass.name'), subject: t('createClass.subject'), group_label: t('createClass.group'), school_year: t('createClass.year') }} save={values => classrooms.update(classroomId, values, { locale })} onSaved={onChanged}/>}
      <Card className="p-5">
        <p className="text-sm font-medium mb-1">{t('manage.settings.code')}</p>
        <div className="flex flex-wrap items-center gap-3">
          <p className="font-mono text-xl font-bold tracking-widest text-[var(--primary)] w-full sm:w-auto sm:flex-1">{code}</p>
          <CopyButton value={code}/>
          {!readOnly && (
            <Btn
              size="sm"
              variant="secondary"
              disabled={regenerate.pending}
              onClick={() =>
                void regenerate.run(async () => {
                  const result = await classrooms.regenerateCode(classroomId, { locale })
                  setCode(result.join_code)
                  onChanged()
                })
              }
            >
              {t('manage.settings.regenerate')}
            </Btn>
          )}
        </div>
      </Card>

      <Card className="p-5">
        <Toggle
          checked={enabled}
          onChange={value => {
            if (readOnly) return
            void toggle.run(async () => {
              await classrooms.toggleCode(classroomId, { locale })
              setEnabled(value)
              onChanged()
            })
          }}
          label={t('manage.settings.joinOpen')}
          disabled={readOnly || toggle.pending}
        />
        {toggle.error && <Alert type="error" message={errorMessage(toggle.error, t('error.unknown'))}/>}
        <p className="text-xs text-[var(--muted-foreground)] mt-1.5">{t('manage.settings.joinOpenHint')}</p>
      </Card>

      {!readOnly && (
        <Card className="p-5">
          <p className="text-sm font-medium mb-1">{t('manage.settings.archive')}</p>
          <p className="text-xs text-[var(--muted-foreground)] mb-3">{t('manage.settings.archiveHint')}</p>
          {archive.error && <div className="mb-3"><Alert message={errorMessage(archive.error, t('error.unknown'))} type="error"/></div>}
          {confirmArchive ? (
            <div className="flex gap-2 justify-end">
              <Btn variant="ghost" onClick={() => setConfirmArchive(false)}>{t('common.cancel')}</Btn>
              <Btn
                variant="danger"
                disabled={archive.pending}
                onClick={() => void archive.run(async () => { await classrooms.archive(classroomId, { locale }); onChanged() })}
              >
                {t('manage.settings.archive')}
              </Btn>
            </div>
          ) : (
            <Btn variant="danger" onClick={() => setConfirmArchive(true)}>{t('manage.settings.archive')}</Btn>
          )}
        </Card>
      )}
    </div>
  )
}

/* ══ Éditeur de quiz manuel ══════════════════════════════════════════ */

interface DraftOption {
  label: string
  is_correct: boolean
}

const QUESTION_TYPES: DraftQuestionType[] = ['single', 'multiple', 'true_false']

type DraftQuestionType = 'single' | 'multiple' | 'true_false'

interface DraftQuestion {
  id?: number
  statement: string
  type: DraftQuestionType
  explanation: string
  options: DraftOption[]
}

export function QuizEditorScreen() {
  const { id, quizId: rawQuizId } = useParams()
  const quizId = Number(rawQuizId)
  const classroomId = Number(id)
  const { t } = useI18n()
  const navigate = useNavigate()

  const [step, setStep] = useState<'settings' | 'questions' | 'preview'>('settings')
  const [settings, setSettings] = useState({
    title: '',
    due_at: '',
    time_limit_min: '',
    max_attempts: '1',
    shuffle: false,
    show_answers: true,
  })
  const [questions, setQuestions] = useState<DraftQuestion[]>([
    { statement: '', type: 'single', explanation: '', options: [
      { label: '', is_correct: true },
      { label: '', is_correct: false },
    ] },
  ])
  const save = useAction()
  const deletedQuestions = useRef(new Set<number>())
  const createdDraft = useRef<ApiQuiz | null>(null)
  const existing = useAsync(signal => quizId ? quizzes.openEditor(quizId, { signal }) : Promise.resolve(null), [quizId])
  useEffect(() => {
    const quiz = existing.data
    if (!quiz) return
    setSettings({ title: quiz.title, due_at: quiz.due_at ? new Date(new Date(quiz.due_at).getTime() - new Date(quiz.due_at).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '', time_limit_min: quiz.time_limit_min?.toString() ?? '', max_attempts: quiz.max_attempts?.toString() ?? '1', shuffle: quiz.shuffle, show_answers: quiz.show_answers })
    setQuestions((quiz.questions ?? []).map(question => ({ id: question.id, statement: question.statement, type: question.type as DraftQuestionType, explanation: question.explanation ?? '', options: question.options ?? [] })))
  }, [existing.data])

  function updateQuestion(index: number, patch: Partial<DraftQuestion>) {
    setQuestions(current => current.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function updateOption(questionIndex: number, optionIndex: number, patch: Partial<DraftOption>) {
    setQuestions(current =>
      current.map((question, i) => {
        if (i !== questionIndex) return question
        return {
          ...question,
          options: question.options.map((option, j) => {
            if (j !== optionIndex) return option
            // Choix unique : une seule bonne réponse à la fois.
            if (patch.is_correct && question.type !== 'multiple') {
              return { ...option, is_correct: optionIndex === j }
            }
            return { ...option, ...patch }
          }),
        }
      }),
    )
  }

  /**
   * L'API crée toujours un brouillon : « publier » crée puis enchaîne
   * immédiatement sur l'endpoint de publication.
   */
  async function submit(publish: boolean) {
    const payload = {
      title: settings.title,
      due_at: settings.due_at ? new Date(settings.due_at).toISOString() : null,
      time_limit_min: settings.time_limit_min ? Number(settings.time_limit_min) : null,
      max_attempts: settings.max_attempts ? Number(settings.max_attempts) : 1,
      shuffle: settings.shuffle,
      show_answers: settings.show_answers,
      questions: questions.map(question => ({
        statement: question.statement,
        type: question.type,
        explanation: question.explanation || null,
        options: question.options
          .filter(option => option.label.trim() !== '')
          .map(option => ({ label: option.label, is_correct: option.is_correct })),
      })),
    }

    const result = await save.run(async () => {
      let created: ApiQuiz
      const targetId = quizId || createdDraft.current?.id
      if (targetId) {
        created = await quizzes.update(targetId, { ...payload, questions: undefined })
        for (const original of (existing.data ?? createdDraft.current)?.questions ?? []) {
          if (!questions.some(question => question.id === original.id) && !deletedQuestions.current.has(original.id)) {
            await quizzes.deleteQuestion(original.id)
            deletedQuestions.current.add(original.id)
          }
        }
        const ids: number[] = []
        for (let i = 0; i < questions.length; i++) {
          const question = questions[i]
          const saved = question.id ? await quizzes.updateQuestion(question.id, payload.questions[i]) : await quizzes.addQuestion(targetId, payload.questions[i])
          ids.push(saved.id)
          updateQuestion(i, { id: saved.id })
        }
        await quizzes.reorderQuestions(targetId, ids)
        if (created.source === 'ai') await quizzes.review(targetId)
      } else {
        created = await quizzes.create(classroomId, payload)
        createdDraft.current = created
        created.questions?.forEach((question, index) => updateQuestion(index, { id: question.id }))
      }
      if (publish) await quizzes.publish(created.id)
      return created
    })
    if (result) navigate(`/app/classes/${classroomId}/manage`)
  }

  const incomplete = !questions.length || (Boolean(quizId) && (existing.loading || Boolean(existing.error) || existing.data?.status !== 'draft')) || questions.some(
    question => !question.statement.trim() || question.options.filter(o => o.label.trim()).length < 2 || !question.options.some(o => o.label.trim() && o.is_correct) || (question.type !== 'multiple' && question.options.filter(o => o.label.trim() && o.is_correct).length !== 1),
  )

  return (
    <div className="max-w-3xl">
      <PageHeader
        title={t('createQuiz.title')}
        subtitle={t(`createQuiz.step.${step}`)}
        back={() => navigate(`/app/classes/${classroomId}/manage`)}
      />

      {save.error && <div className="mb-4"><Alert message={errorMessage(save.error, t('error.unknown'))} type="error"/></div>}
      {existing.error && <Alert message={t('common.error')} type="error"/>}

      {step === 'settings' && (
        <Card className="p-5 space-y-4">
          <Input
            label={t('createQuiz.quizTitle')}
            placeholder={t('createQuiz.quizTitlePlaceholder')}
            value={settings.title}
            onChange={value => setSettings({ ...settings, title: value })}
          />
          <div className="grid grid-cols-2 gap-3">
            <Input label={t('assignment.dueAt')} type="datetime-local" value={settings.due_at} onChange={value => setSettings({ ...settings, due_at: value })}/>
            <Input
              label={t('createQuiz.duration')}
              type="number"
              value={settings.time_limit_min}
              onChange={value => setSettings({ ...settings, time_limit_min: value })}
            />
            <Input
              label={t('createQuiz.maxAttempts')}
              type="number"
              value={settings.max_attempts}
              onChange={value => setSettings({ ...settings, max_attempts: value })}
            />
          </div>
          <Toggle
            checked={settings.shuffle}
            onChange={value => setSettings({ ...settings, shuffle: value })}
            label={t('createQuiz.shuffle')}
          />
          <p className="text-xs text-[var(--muted-foreground)] -mt-2">{t('createQuiz.shuffleHint')}</p>
          <Toggle
            checked={settings.show_answers}
            onChange={value => setSettings({ ...settings, show_answers: value })}
            label={t('createQuiz.showAnswers')}
          />
          <p className="text-xs text-[var(--muted-foreground)] -mt-2">{t('createQuiz.showAnswersHint')}</p>
          <div className="flex justify-end">
            <Btn onClick={() => setStep('questions')} disabled={!settings.title}>{t('createQuiz.questionsStep')}</Btn>
          </div>
        </Card>
      )}

      {step === 'questions' && (
        <div className="space-y-4">
          {questions.map((question, index) => (
            <Card key={index} className="p-5 space-y-3">
              <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">{t('createQuiz.questionN', { n: index + 1 })}</p>
                <div className="flex gap-1">
                  <Btn size="sm" variant="ghost" disabled={index === 0} onClick={() => setQuestions(current => { const next = [...current]; [next[index - 1], next[index]] = [next[index], next[index - 1]]; return next })}>{t('quiz.moveUp')}</Btn>
                  <Btn size="sm" variant="ghost" disabled={index === questions.length - 1} onClick={() => setQuestions(current => { const next = [...current]; [next[index], next[index + 1]] = [next[index + 1], next[index]]; return next })}>{t('quiz.moveDown')}</Btn>
                  <ConfirmButton size="sm" variant="ghost" disabled={questions.length === 1} onClick={() => setQuestions(current => current.filter((_, i) => i !== index))}>{t('common.delete')}</ConfirmButton>
                </div>
                <Select
                  value={question.type}
                  onChange={value => updateQuestion(index, { type: value as DraftQuestionType })}
                   options={QUESTION_TYPES.map(value => ({ value, label: t(`quiz.type.${value}`) }))}
                />
              </div>

              <Textarea
                placeholder={t('createQuiz.statement')}
                value={question.statement}
                onChange={value => updateQuestion(index, { statement: value })}
                rows={2}
              />

              <p className="text-xs text-[var(--muted-foreground)]">{t('createQuiz.optionsHint')}</p>

              <div className="space-y-2">
                {question.options.map((option, optionIndex) => (
                  <div key={optionIndex} className="flex items-center gap-2">
                    <button
                      onClick={() => updateOption(index, optionIndex, { is_correct: !option.is_correct })}
                      className="w-5 h-5 rounded-full border-2 shrink-0 transition-colors"
                      style={{
                        borderColor: option.is_correct ? 'var(--primary)' : 'var(--border)',
                        background: option.is_correct ? 'var(--primary)' : 'transparent',
                      }}
                      aria-label={t('aiQuiz.answer')}
                    />
                    <input
                      value={option.label}
                      onChange={event => updateOption(index, optionIndex, { label: event.target.value })}
                      placeholder={t('createQuiz.optionN', { n: optionIndex + 1 })}
                      aria-label={t('createQuiz.optionN', { n: optionIndex + 1 })}
                      className="flex-1 px-3 py-2 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
                    />
                    {question.options.length > 2 && (
                      <button
                        onClick={() =>
                          setQuestions(current =>
                            current.map((item, i) =>
                              i === index
                                ? { ...item, options: item.options.filter((_, j) => j !== optionIndex) }
                                : item,
                            ),
                          )
                        }
                        className="text-[var(--muted-foreground)] hover:text-[var(--danger)]"
                        aria-label={t('common.delete')}
                      >
                        <Icons.X/>
                      </button>
                    )}
                  </div>
                ))}
              </div>

              {question.options.length < 10 && (
                <Btn
                  size="sm"
                  variant="ghost"
                  onClick={() =>
                    updateQuestion(index, {
                      options: [...question.options, { label: '', is_correct: question.type === 'multiple' && question.options.every(o => !o.is_correct) }],
                    })
                  }
                >
                  <Icons.Plus/> {t('createQuiz.optionN', { n: question.options.length + 1 })}
                </Btn>
              )}

              <Textarea
                placeholder={t('publish.body')}
                value={question.explanation}
                onChange={value => updateQuestion(index, { explanation: value })}
                rows={2}
              />
            </Card>
          ))}

          <div className="flex items-center justify-between">
            <Btn
              variant="secondary"
              onClick={() =>
                setQuestions(current => [
                  ...current,
                  {
                    statement: '',
                    type: 'single',
                    explanation: '',
                    options: [
                      { label: '', is_correct: true },
                      { label: '', is_correct: false },
                    ],
                  },
                ])
              }
            >
              <Icons.Plus/> {t('createQuiz.addQuestion')}
            </Btn>
            <div className="flex gap-2">
              <Btn variant="ghost" onClick={() => setStep('settings')}>{t('createQuiz.backSettings')}</Btn>
              <Btn onClick={() => setStep('preview')} disabled={incomplete}>{t('createQuiz.step.preview')}</Btn>
            </div>
          </div>

          {incomplete && <p className="text-xs text-[var(--danger)]">{t('createQuiz.previewMissing')}</p>}
        </div>
      )}

      {step === 'preview' && (
        <div className="space-y-4">
          <Card className="p-5">
            <h2 className="font-display text-lg font-semibold mb-1">{settings.title}</h2>
            <p className="text-xs text-[var(--muted-foreground)] mb-4">
              {t('student.questions', { count: questions.length })}
              {settings.time_limit_min ? ` · ${t('quiz.minutes', { count: settings.time_limit_min })}` : ''}
            </p>
            {questions.map((question, index) => (
              <div key={index} className="py-3 border-t border-[var(--border)] first:border-t-0">
                <p className="text-sm font-medium mb-2">
                  {index + 1}. {question.statement}
                </p>
                <ul className="text-xs text-[var(--muted-foreground)] space-y-1">
                  {question.options.filter(option => option.label.trim()).map(option => (
                    <li key={option.label} className={option.is_correct ? 'text-green-600' : ''}>
                      {option.is_correct ? '✓ ' : '· '}{option.label}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </Card>

          <div className="flex items-center justify-between">
            <Btn variant="ghost" onClick={() => setStep('questions')}>{t('createQuiz.backSettings')}</Btn>
            <div className="flex gap-2">
              <Btn variant="secondary" onClick={() => void submit(false)} disabled={save.pending || incomplete}>
                {t('quiz.status.draft')}
              </Btn>
              <Btn onClick={() => void submit(true)} disabled={save.pending || incomplete}>
                {t('createQuiz.publish')}
              </Btn>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ══ Résultats d'un quiz ════════════════════════════════════════════ */

export function QuizResultsScreen() {
  const { quizId: rawQuizId } = useParams()
  const quizId = Number(rawQuizId)
  const { t, formatDateTime, locale } = useI18n()
  const navigate = useNavigate()

  const results = useAsync(signal => quizzes.results(quizId, { signal }), [quizId])
  const exportCsv = useAction()
  const [exportError, setExportError] = useState<string | null>(null)

  const data: ApiQuizResults | null = results.data

  return (
    <div className="max-w-3xl">
      <PageHeader
        title={data?.quiz.title ?? t('common.loading')}
        subtitle={t('progress.title')}
        back={() => navigate(-1)}
        actions={
          <Btn
            size="sm"
            variant="secondary"
            onClick={() =>
              void exportCsv
                .run(() => quizzes.exportResults(quizId, { locale }))
                .then(result => {
                  if (!result) setExportError(errorMessage(exportCsv.error, t('error.unknown')))
                })
            }
            disabled={exportCsv.pending}
          >
            CSV
          </Btn>
        }
      />

      {exportError && <div className="mb-4"><Alert message={exportError} type="error"/></div>}

      <AsyncBoundary
        loading={results.loading}
        error={results.error}
        onRetry={results.reload}
        errorMessage={t('common.error')}
        isEmpty={data?.attempts.length === 0}
        empty={<EmptyState message={t('progress.noData')}/>}
      >
        {data && (
          <>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
              <StatTile label={t('progress.students')} value={String(data.summary.students)}/>
              <StatTile label={t('progress.attempts', { count: data.summary.attempts_count })} value={`${data.summary.participation_rate}%`} color="var(--accent)"/>
              <StatTile label={t('progress.average')} value={`${data.summary.average_percentage}%`} color="green"/>
              <StatTile label={t('progress.max')} value={`${data.summary.highest_percentage}%`}/>
            </div>

            <Card className="p-5 mb-5">
              <h2 className="text-sm font-semibold mb-3">{t('progress.distribution')}</h2>
              {data.distribution.map(bucket => {
                return <div key={bucket.min} className="mb-3"><p className="text-sm">{bucket.min} - {bucket.max}% : {bucket.count}</p><ProgressBar value={data.attempts.length ? bucket.count / data.attempts.length * 100 : 0}/></div>
              })}
              <p className="text-sm font-medium mb-3">{t('progress.byQuiz')}</p>
              <div className="space-y-3">
                {data.most_missed.map(item => (
                  <div key={item.question_id}>
                    <div className="flex items-center justify-between text-xs mb-1">
                      <span className="truncate max-w-[70%]">{item.statement}</span>
                      <span className="text-[var(--muted-foreground)]">{t('progress.missRate', { rate: item.miss_rate })}</span>
                    </div>
                    <ProgressBar value={item.miss_rate} color="var(--danger)"/>
                  </div>
                ))}
              </div>
            </Card>

            <Card className="divide-y divide-[var(--border)]">
              {data.attempts.map(attempt => (
                <div key={attempt.id} className="p-4 flex items-center gap-3">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium">{attempt.student?.display_name ?? '—'}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">
                      {formatDateTime(attempt.submitted_at)}
                      {attempt.expired ? ` · ${t('quiz.expired')}` : ''}
                    </p>
                  </div>
                  <span className="text-sm text-[var(--muted-foreground)]">
                    {attempt.score}/{attempt.max_score}
                  </span>
                  <Badge
                    label={`${attempt.percentage}%`}
                    color={attempt.percentage >= 50 ? 'green' : 'red'}
                  />
                </div>
              ))}
            </Card>
          </>
        )}
      </AsyncBoundary>
    </div>
  )
}

/* ══ Correction des devoirs ═════════════════════════════════════════ */

export function GradeScreen() {
  const { assignmentId, id: classroomId } = useParams()
  const { t, formatDateTime, locale } = useI18n()

  const list = useAsync(signal => assignments.list(Number(classroomId), { signal }), [classroomId])
  const submissions = useAsync(signal => assignments.submissions(Number(assignmentId), { signal }), [assignmentId])
  const [selected, setSelected] = useState<ApiSubmission | null>(null)
  const [grade, setGrade] = useState('')
  const [feedback, setFeedback] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const save = useAction()

  const current = list.data?.data.find(item => item.id === Number(assignmentId)) ?? null

  useEffect(() => {
    setGrade(selected?.grade != null ? String(selected.grade) : '')
    setFeedback(selected?.feedback ?? '')
  }, [selected?.id, selected?.grade, selected?.feedback])

  async function submit() {
    if (!selected) return
    const result = await save.run(() =>
      assignments.grade(
        selected.id,
        { grade: grade === '' ? null : Number(grade), feedback: feedback || null },
        { locale },
      ),
    )
    if (result) {
      setNotice(t('grade.saved'))
      submissions.reload()
    }
  }

  return (
    <div className="max-w-4xl">
      <PageHeader
        title={t('grade.title')}
        subtitle={current ? `${current.title} · ${t('manage.dueDate', { date: formatDateTime(current.due_at) })}` : undefined}
        back={() => window.history.back()}
      />

      {notice && <div className="mb-4"><Alert message={notice} type="success"/></div>}
      {save.error && <div className="mb-4"><Alert message={errorMessage(save.error, t('error.unknown'))} type="error"/></div>}

      <div className="grid lg:grid-cols-2 gap-5">
        <div>
          <p className="text-sm font-medium mb-3">{t('grade.copies')}</p>
          {submissions.loading ? (
            <SkeletonList rows={3} className="h-14"/>
          ) : (submissions.data?.data.length ?? 0) === 0 ? (
            <EmptyState message={t('grade.noSubmissions')}/>
          ) : (
            <Card className="divide-y divide-[var(--border)]">
              {(submissions.data?.data ?? []).map(submission => (
                <button
                  key={submission.id}
                  onClick={() => setSelected(submission)}
                  className={`w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-[var(--muted)] ${
                    selected?.id === submission.id ? 'bg-[var(--secondary)]' : ''
                  }`}
                >
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{submission.student?.display_name ?? '—'}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">{formatDateTime(submission.submitted_at)}</p>
                  </div>
                  {submission.is_late && <Badge label={t('grade.lateShort')} color="orange"/>}
                  {submission.grade != null && <Badge label={`${submission.grade}/20`} color="green"/>}
                </button>
              ))}
            </Card>
          )}
        </div>

        <div>
          {selected ? (
            <Card className="p-5 space-y-4">
              <div className="flex items-center gap-3">
                <div className="flex-1">
                  <p className="text-sm font-semibold">{selected.student?.display_name ?? '—'}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{selected.file_name ?? '—'}</p>
                </div>
                <Btn
                  size="sm"
                  variant="secondary"
                  onClick={() => void assignments.downloadSubmission(selected.id, selected.file_name ?? `rendu-${selected.id}`, { locale })}
                >
                  {t('grade.openFile')}
                </Btn>
              </div>

              <Input
                label={t('grade.gradeField')}
                type="number"
                value={grade}
                onChange={setGrade}
              />
              <Textarea
                label={t('grade.feedback')}
                placeholder={t('grade.feedbackPlaceholder')}
                value={feedback}
                onChange={setFeedback}
              />

              <Btn full onClick={() => void submit()} disabled={save.pending}>
                {t('grade.save')}
              </Btn>
            </Card>
          ) : (
            <EmptyState message={t('grade.select')}/>
          )}
        </div>
      </div>
    </div>
  )
}
