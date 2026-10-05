import { Link } from 'react-router-dom'
import { useI18n } from '../i18n'
import { progression } from '../lib/endpoints'
import { useAsync } from '../lib/useAsync'
import type { ApiStudentProgress } from '../lib/types'
import { Alert, AsyncBoundary, Badge, Card, EmptyState, PageHeader, StatTile } from '../components/UI'

export function StudentProgressScreen() {
  const { t, locale, formatDateTime } = useI18n()
  const progress = useAsync(signal => progression.me({ locale, signal }), [locale])
  const groups = new Map<number, ApiStudentProgress['history']>()
  for (const attempt of [...(progress.data?.history ?? [])].reverse()) {
    groups.set(attempt.quiz_id, [...(groups.get(attempt.quiz_id) ?? []), attempt])
  }
  return <div>
    <PageHeader title={t('nav.progression')} subtitle={t('progress.fullHistory')}/>
    <AsyncBoundary loading={progress.loading} error={progress.error} onRetry={progress.reload} errorMessage={t('common.error')} isEmpty={!groups.size} empty={<EmptyState message={t('progress.noData')}/> }>
      {progress.data && <>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
          <StatTile label={t('student.stats.quizzes')} value={String(progress.data.totals.quizzes_taken)}/>
          <StatTile label={t('student.stats.average')} value={`${progress.data.totals.average_percentage}%`}/>
        </div>
        <Alert message={progress.data.trend.delta_percentage_points == null ? t('progress.insufficientTrend') : t('progress.trend', { delta: progress.data.trend.delta_percentage_points })}/>
        <div className="space-y-4 mt-5">
          {[...groups].map(([quizId, attempts]) => <Card key={quizId} className="p-4">
            <h2 className="font-semibold mb-3">{attempts[0].quiz_title ?? t('nav.quiz')}</h2>
            <div className="space-y-3">{attempts.map(attempt => <div key={attempt.attempt_id} className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border-subtle)] pt-3">
              <div><p>{t('progress.attemptNumber', { n: attempt.attempt_no })} · {attempt.score}/{attempt.max_score}</p><p className="text-xs text-[var(--muted-foreground)]">{formatDateTime(attempt.submitted_at)}</p></div>
              <Badge label={`${attempt.percentage}%`} color={attempt.percentage >= 50 ? 'green' : 'red'}/>
              {attempt.classroom_id != null && <Link className="inline-flex items-center min-h-11 text-[var(--primary)]" to={`/app/classes/${attempt.classroom_id}/quizzes/${quizId}?attemptId=${attempt.attempt_id}`}>{t('progress.viewResult')}</Link>}
            </div>)}</div>
          </Card>)}
        </div>
      </>}
    </AsyncBoundary>
  </div>
}
