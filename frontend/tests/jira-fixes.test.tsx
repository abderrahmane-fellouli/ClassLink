import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ReactNode } from 'react'
import { I18nProvider } from '../src/i18n'
import { setStoredLocale } from '../src/lib/session'
import { en } from '../src/i18n/en'
import { CopyButton } from '../src/components/CopyButton'
import { StudentDashboard } from '../src/screens/StudentScreens'
import { StudentProgressScreen } from '../src/screens/StudentProgressScreen'
import { ClassProgressScreen } from '../src/screens/TeacherScreens'
import { TeacherClassScreen } from '../src/screens/TeacherClassScreens'
import { AdminAuditScreen } from '../src/screens/AdminScreens'
import { ProfileScreen } from '../src/screens/ProfileScreen'
import { jsonResponse } from './setup'

const auth = vi.hoisted(() => ({ user: { id: 1, display_name: 'Fixture', role: 'student', locale: 'en' }, role: 'student', signOut: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../src/context/AuthContext', () => ({ useAuth: () => auth }))
const classroom = { id: 1, name: 'Web', subject: 'Web', status: 'active', is_read_only: false, join_code: 'ABCDEFGH', join_enabled: true }
function mount(child: ReactNode, path = '/', pattern = path) {
  return render(<I18nProvider><MemoryRouter initialEntries={[path]}><Routes><Route path={pattern} element={child}/><Route path="/" element={<p>Home destination</p>}/></Routes></MemoryRouter></I18nProvider>)
}
function mockApi(responses: Record<string, unknown | (() => unknown)>) {
  const fetcher = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const path = new URL(String(input), 'http://localhost').pathname.replace(/^\/api/, '')
    const key = `${init?.method ?? 'GET'} ${path}`
    if (!(key in responses)) throw new Error(`Unexpected request: ${key}`)
    const response = typeof responses[key] === 'function' ? (responses[key] as () => unknown)() : responses[key]
    return jsonResponse(response)
  })
  vi.stubGlobal('fetch', fetcher)
  return fetcher
}
beforeEach(() => { setStoredLocale('en'); auth.signOut.mockClear() })

it('copies the actual code, confirms only success and resets confirmation when the code changes', async () => {
  const user = userEvent.setup()
  const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
  const view = render(<CopyButton value="ABCDEFGH"/>, { wrapper: ({ children }) => <I18nProvider>{children}</I18nProvider> })
  await user.click(screen.getByRole('button', { name: 'Copy' }))
  expect(write).toHaveBeenCalledWith('ABCDEFGH')
  expect(screen.getByRole('status')).toHaveTextContent('Copied')
  view.rerender(<CopyButton value="NEWCODE2"/>)
  expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument()
})

it('a denied clipboard operation does not falsely confirm success', async () => {
  const user = userEvent.setup()
  vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('denied'))
  mount(<CopyButton value="ABCDEFGH"/>, '/copy')
  await user.click(screen.getByRole('button', { name: 'Copy' }))
  expect(await screen.findByText(en['copy.failed'])).toBeInTheDocument()
  expect(screen.queryByRole('status')).not.toBeInTheDocument()
})

it('student home displays the pinned/newest announcement feed with class context', async () => {
  mockApi({ 'GET /classes': { data: [] }, 'GET /me/progress': { totals: {}, history: [], trend: {} },
    'GET /me/announcements': { data: [{ id: 1, title: 'Pinned feed', body: 'First', pinned: true, classroom_id: 2, classroom: { name: 'Accepted class' } }, { id: 2, title: 'Newest feed', body: 'Second', pinned: false, classroom_id: 2 }] } })
  mount(<StudentDashboard/>, '/dashboard')
  await screen.findByText('Pinned feed')
  expect(screen.getByText('Pinned feed').compareDocumentPosition(screen.getByText('Newest feed')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Accepted class' })).toHaveAttribute('href', '/app/classes/2')
})

it('complete student progression shows more than five quizzes and their actual scores', async () => {
  const history = Array.from({ length: 8 }, (_, i) => ({ attempt_id: i + 1, quiz_id: i + 1, quiz_title: `Quiz ${i + 1}`, attempt_no: 1, score: i, max_score: 10, percentage: i * 10, classroom_id: 1 }))
  mockApi({ 'GET /me/progress': { totals: { quizzes_taken: 8 }, history, trend: { delta_percentage_points: 70 } } })
  mount(<StudentProgressScreen/>, '/progress')
  expect(await screen.findByText('Quiz 1')).toBeInTheDocument()
  expect(screen.getByText('Quiz 8')).toBeInTheDocument()
  expect(screen.getAllByRole('link', { name: 'View result' })).toHaveLength(8)
  expect(screen.getByText(/Attempt 1 · 7\/10/)).toBeInTheDocument()
})

it('zero participation still displays inactive members and honest empty average', async () => {
  mockApi({ 'GET /classes': { data: [classroom] }, 'GET /classes/1/progress': { totals: { attempts: 0, students: 1, class_average: 0, participation_rate: 0 }, most_missed: [], inactive_student_ids: [3], inactive_students: [{ id: 3, display_name: 'Never participated' }] } })
  mount(<ClassProgressScreen/>, '/progress')
  expect(await screen.findByText('Never participated')).toBeInTheDocument()
  expect(screen.getByText('0%')).toBeInTheDocument()
  expect(screen.getByText('—')).toBeInTheDocument()
})

it('profile current-session logout requires confirmation and returns to the homepage', async () => {
  mockApi({ 'GET /notifications': { data: [] }, 'GET /me/notification-preferences': { email_digest: true, types: {} } })
  mount(<ProfileScreen/>, '/profile')
  await userEvent.click(screen.getByRole('tab', { name: 'Security' }))
  await userEvent.click(screen.getByRole('button', { name: 'Sign out' }))
  expect(auth.signOut).not.toHaveBeenCalled()
  await userEvent.click(screen.getByRole('button', { name: 'Confirm' }))
  expect(await screen.findByText('Home destination')).toBeInTheDocument()
  expect(auth.signOut).toHaveBeenCalledOnce()
})

it('audit controls send actual class category and selected date bounds', async () => {
  const fetcher = mockApi({ 'GET /admin/audit-logs': { data: [] } })
  mount(<AdminAuditScreen/>, '/audit')
  const selector = screen.getByRole('combobox')
  await userEvent.selectOptions(selector, 'class')
  fireEvent.change(screen.getByLabelText(en['audit.to']), { target: { value: '2026-10-01' } })
  await waitFor(() => expect(fetcher.mock.calls.some(([url]) => String(url).includes('action=class') && String(url).includes('to=2026-10-01'))).toBe(true))
})

for (const terminal of ['done', 'failed'] as const) {
  it(`AI polling follows queued → processing → ${terminal} and surfaces the terminal outcome`, async () => {
    let polls = 0
    const responses = { 'GET /classes/1': classroom, ...Object.fromEntries(['members', 'join-requests', 'announcements', 'materials', 'quizzes', 'assignments', 'flashcards'].map(route => [`GET /classes/1/${route}`, { data: [] }])),
      'POST /classes/1/ai/generate': { id: 9, target: 'quiz', status: 'queued' },
      'GET /ai/jobs/9': () => ({ id: 9, target: 'quiz', status: ++polls === 1 ? 'processing' : terminal, quiz_id: terminal === 'done' ? 20 : null }) }
    mockApi(responses)
    mount(<TeacherClassScreen/>, '/classes/1/manage?tab=quizzes', '/classes/:id/manage')
    await userEvent.upload(await screen.findByLabelText(en['aiQuiz.dropHint']), new File(['%PDF-fixture'], 'course.pdf', { type: 'application/pdf' }))
    await userEvent.click(screen.getByRole('button', { name: en['aiQuiz.start'] }))
    if (terminal === 'done') await screen.findByText(en['status.done'], {}, { timeout: 11000 })
    else expect(await screen.findByRole('link', { name: en['aiQuiz.manualFallback'] }, { timeout: 11000 })).toHaveAttribute('href', '/app/classes/1/manage/quizzes/new')
    expect(polls).toBe(2)
  }, 15000)
}
