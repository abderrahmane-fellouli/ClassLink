import { describe, expect, it } from 'vitest'
import { describeNotification } from '../src/components/AppShell'
import { fr } from '../src/i18n/fr'
import type { ApiNotification } from '../src/lib/types'

/**
 * Le rendu d'une notification depend du `type` et des cles de `payload` emis
 * par `backend/app/Services/NotificationService.php`.
 *
 * Regression : le frontend comparait a des types a points (`quiz.published`)
 * alors que le backend emet des constantes a underscores (`quiz_published`).
 * Aucune comparaison ne aboutissait, donc *toute* notification s'affichait
 * comme « Nouvelle notification ».
 */
const t = (key: string, params?: Record<string, string | number>): string => {
  const template = (fr as Record<string, string>)[key] ?? key
  return template.replace(/\{\{(\w+)\}\}/g, (_, name: string) => String(params?.[name] ?? ''))
}

const notification = (type: string, payload: Record<string, unknown> = {}): ApiNotification =>
  ({ id: 1, type, payload, read_at: null, created_at: '2026-10-03T00:00:00Z' }) as ApiNotification

describe('describeNotification', () => {
  it('translates a quiz publication using the backend payload', () => {
    const text = describeNotification(
      notification('quiz_published', { title: 'Quiz 1', classroom_name: 'Maths' }),
      t,
    )

    expect(text).toContain('Quiz 1')
    expect(text).not.toBe(fr['notif.unknown'])
  })

  it('translates an announcement publication (Phase 1.5)', () => {
    const text = describeNotification(
      notification('announcement_published', { title: 'Consignes', classroom_name: 'Maths' }),
      t,
    )

    expect(text).toContain('Consignes')
    expect(text).toContain('Maths')
    expect(text).not.toBe(fr['notif.unknown'])
  })

  it('translates an assignment publication (Phase 1.5)', () => {
    const text = describeNotification(
      notification('assignment_published', { title: 'TP 3', classroom_name: 'Maths' }),
      t,
    )

    expect(text).toContain('TP 3')
    expect(text).not.toBe(fr['notif.unknown'])
  })

  it('translates a join request with the student and classroom names', () => {
    const text = describeNotification(
      notification('join_requested', { student_name: 'Amine', classroom_name: 'Maths' }),
      t,
    )

    expect(text).toContain('Amine')
    expect(text).toContain('Maths')
  })

  it('translates membership decisions and removal', () => {
    expect(describeNotification(notification('membership_accepted', { classroom_name: 'Maths' }), t)).toContain(
      'acceptée',
    )
    expect(describeNotification(notification('membership_rejected', { classroom_name: 'Maths' }), t)).toContain(
      'refusée',
    )
    expect(describeNotification(notification('membership_removed', { classroom_name: 'Maths' }), t)).toContain(
      'retiré',
    )
  })

  it('translates partner notifications', () => {
    expect(
      describeNotification(
        notification('partner_request_received', { from_name: 'Sara', classroom_name: 'Maths' }),
        t,
      ),
    ).toContain('Sara')
    expect(describeNotification(notification('partner_request_answered', { status: 'accepted' }), t)).toContain(
      'Acceptée',
    )
  })

  it('falls back to the generic message for an unknown type', () => {
    expect(describeNotification(notification('something_new'), t)).toBe(fr['notif.unknown'])
  })

  it('does not throw when the payload is missing or incomplete', () => {
    expect(() => describeNotification(notification('quiz_published'), t)).not.toThrow()
    expect(describeNotification(notification('graded'), t)).not.toBe('')
  })
})
