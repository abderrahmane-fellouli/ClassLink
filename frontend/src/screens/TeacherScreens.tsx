import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useI18n } from '../i18n'
import { useAuth } from '../context/AuthContext'
import { CopyButton } from '../components/CopyButton'
import { errorMessage, firstFieldError } from '../lib/api'
import { classrooms, progression, quizzes } from '../lib/endpoints'
import { useAction, useAsync } from '../lib/useAsync'
import type { ApiClassroom } from '../lib/types'
import {
  Alert,
  AsyncBoundary,
  Badge,
  Btn,
  ConfirmButton,
  Card,
  EmptyState,
  Icons,
  PageHeader,
  ProgressBar,
  SkeletonStats,
  StatTile,
} from '../components/UI'

/* ══ Tableau de bord enseignant ══════════════════════════════════════ */

export function TeacherDashboard() {
  const { t } = useI18n()
  const { user } = useAuth()

  const classes = useAsync(signal => classrooms.list({ signal }), [])
  const myClasses = classes.data?.data.filter(item => item.status === 'active') ?? []

  /* Comptages agrégés : chaque appel est déjà limité à une classe. */
  const aggregates = useAsync(
    async signal => {
      const results = await Promise.all(
        myClasses.map(async classroom => {
          const [members, requests, quizList] = await Promise.all([
            classrooms.members(classroom.id, { signal }),
            classrooms.joinRequests(classroom.id, { signal }),
            quizzes.listForTeacher(classroom.id, { signal }),
          ])
          const accepted = members.data.filter(item => item.status === 'accepted').length
          const pending = requests.data.length
          const published = quizList.data.filter(item => item.status === 'published').length
          return { classroomId: classroom.id, accepted, pending, published }
        }),
      )
      return results
    },
    [myClasses.map(item => item.id).join(',')],
  )

  const totals = (aggregates.data ?? []).reduce(
    (accumulator, item) => ({
      students: accumulator.students + item.accepted,
      pending: accumulator.pending + item.pending,
      quizzes: accumulator.quizzes + item.published,
    }),
    { students: 0, pending: 0, quizzes: 0 },
  )

  return (
    <div>
      <PageHeader
        title={`${t('nav.dashboard')}${user?.display_name ? ` · ${user.display_name}` : ''}`}
        actions={
          <Link to="/app/classes/new">
            <Btn size="sm"><Icons.Plus/> {t('teacher.newClass')}</Btn>
          </Link>
        }
      />

      {aggregates.loading ? (
        <SkeletonStats/>
      ) : aggregates.error ? (
        <Alert message={errorMessage(aggregates.error, t('error.unknown'))} type="error"/>
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
          <StatTile label={t('teacher.stats.classes')} value={String(myClasses.length)}/>
          <StatTile label={t('teacher.stats.students')} value={String(totals.students)} color="var(--accent)"/>
          <StatTile label={t('teacher.stats.pending')} value={String(totals.pending)} color="var(--danger)"/>
          <StatTile label={t('teacher.stats.quizzes')} value={String(totals.quizzes)} color="green"/>
        </div>
      )}

      <div className="flex items-center justify-between mb-4">
        <h2 className="font-display text-lg font-semibold">{t('teacher.myClasses')}</h2>
        <div className="flex gap-2">
          <Link to="/app/requests"><Btn size="sm" variant="ghost">{t('teacher.requestsLink')}</Btn></Link>
          <Link to="/app/progression"><Btn size="sm" variant="ghost">{t('nav.progression')}</Btn></Link>
        </div>
      </div>

      <AsyncBoundary
        loading={classes.loading}
        error={classes.error}
        onRetry={classes.reload}
        errorMessage={t('common.error')}
        isEmpty={myClasses.length === 0}
        empty={
          <EmptyState
            message={t('student.noClasses')}
            action={
              <Link to="/app/classes/new">
                <Btn size="sm">{t('createClass.title')}</Btn>
              </Link>
            }
          />
        }
      >
        <div className="grid sm:grid-cols-2 gap-4">
          {myClasses.map(classroom => {
            const stats = (aggregates.data ?? []).find(item => item.classroomId === classroom.id)
            return (
              <Link key={classroom.id} to={`/app/classes/${classroom.id}/manage`}>
                <Card className="p-5 h-full hover:border-[var(--primary)]/40 transition-colors">
                  <div className="flex items-start justify-between gap-2 mb-1">
                    <h3 className="font-semibold">{classroom.name}</h3>
                    <Badge
                      label={t(classroom.status === 'archived' ? 'class.status.archived' : 'class.status.active')}
                      color={classroom.status === 'archived' ? 'default' : 'green'}
                    />
                  </div>
                  <p className="text-xs text-[var(--muted-foreground)] mb-3">
                    {classroom.subject} · {classroom.group_label} · {classroom.school_year}
                  </p>
                  <div className="flex items-center gap-3 text-xs text-[var(--muted-foreground)]">
                    <span className="flex items-center gap-1">
                      <Icons.Users/> {t('teacher.memberCount', { count: stats?.accepted ?? 0 })}
                    </span>
                    {(stats?.pending ?? 0) > 0 && (
                      <span className="flex items-center gap-1" style={{ color: 'var(--danger)' }}>
                        <Icons.Bell/> {t('teacher.pendingBadge', { count: stats?.pending ?? 0 })}
                      </span>
                    )}
                    <span className="flex items-center gap-1">
                      <Icons.Quiz/> {t('teacher.quizCount', { count: stats?.published ?? 0 })}
                    </span>
                  </div>
                </Card>
              </Link>
            )
          })}
        </div>
      </AsyncBoundary>
    </div>
  )
}

/* ══ Demandes d'adhésion, toutes classes ════════════════════════════ */

export function TeacherRequestsScreen() {
  const { t, formatDate } = useI18n()
  const classes = useAsync(signal => classrooms.list({ signal }), [])
  const myClasses = classes.data?.data ?? []

  const requests = useAsync(
    async signal => {
      const all = await Promise.all(
        myClasses.map(async classroom => {
          const response = await classrooms.joinRequests(classroom.id, { signal })
          return {
            classroom,
            members: response.data.filter(item => item.status === 'pending'),
          }
        }),
      )
      return all.filter(item => item.members.length > 0)
    },
    [myClasses.map(item => item.id).join(',')],
  )

  const accept = useAction()

  async function acceptAll(classroomId: number) {
    await accept.run(() => classrooms.acceptAll(classroomId))
    requests.reload()
  }

  return (
    <div>
      <PageHeader title={t('nav.requests')}/>

      <AsyncBoundary
        loading={requests.loading}
        error={requests.error}
        onRetry={requests.reload}
        errorMessage={t('common.error')}
        isEmpty={(requests.data?.length ?? 0) === 0}
        empty={<EmptyState message={t('manage.noRequests')}/>}
      >
        <div className="space-y-6">
          {(requests.data ?? []).map(group => (
            <Card key={group.classroom.id} className="overflow-hidden">
              <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[var(--border)]">
                <div>
                  <p className="text-sm font-semibold">{group.classroom.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">{group.classroom.subject}</p>
                </div>
                <div className="flex gap-2">
                   <ConfirmButton
                    size="sm"
                    variant="success"
                    onClick={() => void acceptAll(group.classroom.id)}
                    disabled={accept.pending}
                  >
                    {t('manage.accepted')} · {group.members.length}
                   </ConfirmButton>
                  <Link to={`/app/classes/${group.classroom.id}/manage?tab=requests`}>
                    <Btn size="sm" variant="secondary">{t('common.open')}</Btn>
                  </Link>
                </div>
              </div>
              <div className="divide-y divide-[var(--border)]">
                {group.members.map(member => (
                  <div key={member.membership_id} className="px-4 py-3 flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style={{ background: 'var(--accent)', color: 'white' }}>
                      {member.user?.initials ?? '—'}
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm">{member.user?.display_name ?? '—'}</p>
                      <p className="text-xs text-[var(--muted-foreground)]">
                        {t('manage.joinedOn', { date: formatDate(member.requested_at) })}
                      </p>
                    </div>
                    <Badge label={t('join.status.pending')} color="orange"/>
                  </div>
                ))}
              </div>
            </Card>
          ))}
        </div>
      </AsyncBoundary>
    </div>
  )
}

/* ══ Progression d'une classe ═══════════════════════════════════════ */

export function ClassProgressScreen() {
  const { t } = useI18n()
  const [classroomId, setClassroomId] = useState<number | null>(null)

  const classes = useAsync(signal => classrooms.list({ signal }), [])

  useEffect(() => {
    const first = classes.data?.data[0]
    if (first && classroomId === null) setClassroomId(first.id)
  }, [classes.data, classroomId])

  const progress = useAsync(
    signal => (classroomId ? progression.classroom(classroomId, { signal }) : Promise.resolve(null)),
    [classroomId],
  )

  const totals = progress.data?.totals

  return (
    <div>
      <PageHeader
        title={t('progress.title')}
        actions={
          <select
            value={classroomId ?? ''}
            onChange={event => setClassroomId(Number(event.target.value))}
            className="px-3 py-2 text-sm border border-[var(--border)] rounded-lg bg-white outline-none focus:ring-2 focus:ring-[var(--primary)]"
            aria-label={t('progress.noClass')}
          >
            {(classes.data?.data ?? []).map(item => (
              <option key={item.id} value={item.id}>{item.name}</option>
            ))}
          </select>
        }
      />

      {!classroomId ? (
        <EmptyState message={t('progress.noClass')}/>
      ) : (
        <>
          <AsyncBoundary
            loading={progress.loading}
            error={progress.error}
            onRetry={progress.reload}
            errorMessage={t('common.error')}
            isEmpty={!totals}
            empty={<EmptyState message={t('progress.noData')}/>}
          >
            {totals && (
              <>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
                  <StatTile label={t('progress.students')} value={String(totals.students)}/>
                  <StatTile label={t('progress.stat.quizAverage')} value={totals.attempts ? `${totals.class_average}%` : '—'} color="var(--accent)"/>
                  <StatTile label={t('progress.stat.submission')} value={`${totals.participation_rate}%`} color="green"/>
                  <StatTile
                    label={t('progress.stat.atRisk')}
                    value={String(progress.data?.inactive_student_ids.length ?? 0)}
                    color="var(--danger)"
                  />
                </div>

                <Card className="p-5 mb-6">
                  <p className="text-sm font-medium mb-3">{t('progress.mostMissed')}</p>
                  {(progress.data?.most_missed.length ?? 0) === 0 ? (
                    <p className="text-sm text-[var(--muted-foreground)]">{t('progress.noData')}</p>
                  ) : (
                    <div className="space-y-3">
                      {(progress.data?.most_missed ?? []).map(item => (
                        <div key={item.question_id}>
                          <div className="flex items-center justify-between text-xs mb-1">
                            <span className="truncate max-w-[70%]">{item.statement ?? item.quiz_title ?? '—'}</span>
                            <span className="text-[var(--muted-foreground)]">
                              {t('progress.missRate', { rate: item.miss_rate })}
                            </span>
                          </div>
                          <ProgressBar value={item.miss_rate} color="var(--danger)"/>
                        </div>
                      ))}
                    </div>
                  )}
                </Card>

                {(progress.data?.inactive_student_ids.length ?? 0) > 0 && (
                  <Card className="p-5">
                    <p className="text-sm font-medium mb-2">{t('progress.inactive')}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">
                       {progress.data?.inactive_students.map(student => student.display_name).join(', ')}
                    </p>
                  </Card>
                )}
              </>
            )}
          </AsyncBoundary>
        </>
      )}
    </div>
  )
}

/* ══ Création d'une classe ══════════════════════════════════════════ */

export function CreateClassScreen() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const create = useAction()

  const [form, setForm] = useState({
    name: '',
    subject: '',
    group_label: '',
    school_year: `${new Date().getFullYear()}/${new Date().getFullYear() + 1}`,
  })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [created, setCreated] = useState<ApiClassroom | null>(null)

  async function submit() {
    setFieldErrors({})
    const result = await create.run(() => classrooms.create(form))
    if (result) {
      setCreated(result as ApiClassroom)
    } else {
      setFieldErrors({
        name: firstFieldError(create.error, 'name', t('error.unknown')) ?? '',
        subject: firstFieldError(create.error, 'subject', t('error.unknown')) ?? '',
        group_label: firstFieldError(create.error, 'group_label', t('error.unknown')) ?? '',
        school_year: firstFieldError(create.error, 'school_year', t('error.unknown')) ?? '',
      })
    }
  }

  if (created) {
    return (
      <div className="max-w-lg">
        <PageHeader title={t('createClass.done')}/>
        <Card className="p-6 text-center">
          <div className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3" style={{ background: 'rgba(34,197,94,0.12)', color: 'green' }}>
            <Icons.Check/>
          </div>
          <p className="text-sm text-[var(--muted-foreground)] mb-5">{t('createClass.doneBody')}</p>
          <div className="px-4 py-3 rounded-lg bg-[var(--secondary)] mb-5">
            <p className="text-xs text-[var(--muted-foreground)] mb-1">{t('manage.settings.code')}</p>
            <p className="font-mono text-2xl font-bold tracking-widest text-[var(--primary)]">{created.join_code}</p>
            <CopyButton value={created.join_code ?? ''}/>
          </div>
          <div className="flex gap-2 justify-center">
            <Btn onClick={() => navigate(`/app/classes/${created.id}/manage`)}>{t('createClass.manage')}</Btn>
            <Btn variant="secondary" onClick={() => setCreated(null)}>{t('teacher.newClass')}</Btn>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="max-w-lg">
      <PageHeader title={t('createClass.title')} subtitle={t('createClass.subtitle')}/>

      <Card className="p-5 space-y-4">
        {create.error && <Alert message={errorMessage(create.error, t('error.unknown'))} type="error"/>}

        <div className="flex flex-col gap-1.5">
          <label htmlFor="class-name" className="text-sm font-medium">{t('createClass.name')}</label>
          <input
            id="class-name"
            value={form.name}
            onChange={event => setForm({ ...form, name: event.target.value })}
            placeholder={t('createClass.namePlaceholder')}
            className="w-full px-3.5 py-2.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          {fieldErrors.name && <p className="text-xs text-[var(--danger)]">{fieldErrors.name}</p>}
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="class-subject" className="text-sm font-medium">{t('createClass.subject')}</label>
          <input
            id="class-subject"
            value={form.subject}
            onChange={event => setForm({ ...form, subject: event.target.value })}
            placeholder={t('createClass.subjectPlaceholder')}
            className="w-full px-3.5 py-2.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
          />
          {fieldErrors.subject && <p className="text-xs text-[var(--danger)]">{fieldErrors.subject}</p>}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="class-group" className="text-sm font-medium">{t('createClass.group')}</label>
            <input
              id="class-group"
              value={form.group_label}
              onChange={event => setForm({ ...form, group_label: event.target.value })}
              placeholder={t('createClass.groupPlaceholder')}
              className="w-full px-3.5 py-2.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
            />
            {fieldErrors.group_label && <p className="text-xs text-[var(--danger)]">{fieldErrors.group_label}</p>}
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="class-year" className="text-sm font-medium">{t('createClass.year')}</label>
            <input
              id="class-year"
              value={form.school_year}
              onChange={event => setForm({ ...form, school_year: event.target.value })}
              className="w-full px-3.5 py-2.5 text-sm border border-[var(--border)] rounded-lg outline-none focus:ring-2 focus:ring-[var(--primary)]"
            />
            {fieldErrors.school_year && <p className="text-xs text-[var(--danger)]">{fieldErrors.school_year}</p>}
          </div>
        </div>

        <div className="flex justify-end">
          <Btn onClick={() => void submit()} disabled={create.pending || !form.name || !form.subject}>
            {create.pending ? t('common.loading') : t('createClass.submit')}
          </Btn>
        </div>
      </Card>
    </div>
  )
}
