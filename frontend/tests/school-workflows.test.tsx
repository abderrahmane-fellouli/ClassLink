import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { I18nProvider } from '../src/i18n'
import { NoticeComposer, OfficialGradeEditor } from '../src/screens/SchoolScreens'
import { api } from '../src/lib/api'
import type { Group } from '../src/lib/school'

const auth = vi.hoisted(() => ({ user: { id: 1, display_name: 'Fixture', role: 'admin', locale: 'en' }, role: 'admin', token: null, signOut: vi.fn().mockResolvedValue(undefined) }))
vi.mock('../src/context/AuthContext', () => ({ useAuth: () => auth }))
vi.mock('../src/lib/api', () => ({ api: { get: vi.fn(), put: vi.fn(), post: vi.fn(), postForm: vi.fn(), download: vi.fn() }, errorMessage: (e: Error) => e.message }))

const editor = {
  assessment: { id: 1, offering_id: 1, title: 'Synthetic assessment', type: 'exam', assessed_on: '2026-10-07', maximum_score: 20, coefficient: 1, state: 'draft', version: 1, roster_version: 3, draft_revision_id: 1, published_revision_id: null },
  current_roster_version: 3, read_only: false,
  entries: [{ student_id: 42, display_name: 'Synthetic Student', score: null, status: 'ungraded', feedback: null }], history: [],
}
function mount() {
  const router = createMemoryRouter([{ path: '/app/school/assessments/:id', element: <OfficialGradeEditor/> }, { path: '/app/school', element: <p>Workspace destination</p> }], { initialEntries: ['/app/school/assessments/1'] })
  render(<I18nProvider><RouterProvider router={router}/></I18nProvider>)
  return router
}

beforeEach(() => {
  vi.clearAllMocks(); localStorage.setItem('classlink.locale', 'en')
  vi.mocked(api.get).mockResolvedValue(structuredClone(editor))
  vi.mocked(api.put).mockResolvedValue({ version: 2 })
})

describe('multi-group class notices', () => {
  const groups: Group[] = [
    { id: 1, name: 'Group Alpha', official_code: 'A', school_year: '2026', academic_year_id: 1, status: 'active', join_enabled: true, coordinator_id: null, coordinator_can_manage_roster: false },
    { id: 2, name: 'Group Beta', official_code: 'B', school_year: '2026', academic_year_id: 1, status: 'active', join_enabled: true, coordinator_id: null, coordinator_can_manage_roster: false },
    { id: 3, name: 'Group Archived', official_code: 'C', school_year: '2026', academic_year_id: 1, status: 'archived', join_enabled: true, coordinator_id: null, coordinator_can_manage_roster: false },
  ]

  it('sends the union of the selected groups and shows the per-group breakdown', async () => {
    const user = userEvent.setup()
    vi.mocked(api.post).mockResolvedValue({
      preview_id: 'notice-1', audience: 'classes', recipient_count: 9,
      breakdown: [
        { classroom_id: 1, recipient_count: 5 },
        { classroom_id: 2, recipient_count: 4 },
      ],
      recipients_preview: [{ id: 10, display_name: 'Synthetic Student' }],
    })
    render(<I18nProvider><NoticeComposer groups={groups}/></I18nProvider>)

    await user.click(screen.getByRole('checkbox', { name: 'Group Alpha' }))
    await user.click(screen.getByRole('checkbox', { name: 'Group Beta' }))
    await user.click(screen.getByRole('button', { name: 'Preview recipients' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/school/notices/preview', { audience: 'classes', group_ids: [1, 2] }, { locale: 'en' }))
    expect(await screen.findByText('Recipients: 9')).toBeInTheDocument()
    expect(screen.getByText(/Group Alpha: 5/)).toBeInTheDocument()
    expect(screen.getByText(/Group Beta: 4/)).toBeInTheDocument()
  })

  it('keeps the preview disabled until a group is selected and ignores archived groups', async () => {
    const user = userEvent.setup()
    render(<I18nProvider><NoticeComposer groups={groups}/></I18nProvider>)

    expect(screen.getByRole('button', { name: 'Preview recipients' })).toBeDisabled()
    expect(screen.queryByRole('checkbox', { name: 'Group Archived' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Group Alpha' }))
    expect(screen.getByRole('button', { name: 'Preview recipients' })).toBeEnabled()
  })
})

describe('institutional grade workflow safety', () => {
  it('keeps zero distinct from missing scores when saving a graded draft', async () => {
    const user = userEvent.setup(); mount()
    await screen.findByRole('heading', { name: 'Synthetic assessment' })
    await user.selectOptions(await screen.findByLabelText('Status Synthetic Student 42'), 'graded')
    await user.type(screen.getByLabelText('Score Synthetic Student 42'), '0')
    await user.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())
    const body = vi.mocked(api.put).mock.calls[0][1] as { rows: { score: string; status: string }[] }
    expect(body.rows[0]).toMatchObject({ score: '0', status: 'graded' })
  })

  it('blocks confirming an import that still contains validation errors', async () => {
    vi.mocked(api.postForm).mockResolvedValue({ batch_id: 'synthetic-preview', errors: [{ row: 2, reason: 'invalid_score' }], summary: { changed: 1, unchanged: 0, missing: 0 } })
    const user = userEvent.setup(); mount()
    await screen.findByRole('heading', { name: 'Synthetic assessment' })
    await user.upload(screen.getByLabelText('Private file'), new File(['synthetic CSV'], 'notes.csv', { type: 'text/csv' }))
    await user.click(screen.getByRole('button', { name: 'Preview file' }))
    expect(await screen.findByRole('button', { name: 'Confirm draft import' })).toBeDisabled()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('requires an explicit choice before losing unsaved grades on navigation', async () => {
    const user = userEvent.setup(); const router = mount()
    await screen.findByRole('heading', { name: 'Synthetic assessment' })
    await user.type(await screen.findByLabelText('Score Synthetic Student 42'), '12')
    await user.click(screen.getByRole('link', { name: 'Back to my workspace' }))
    expect(await screen.findByRole('dialog')).toBeVisible()
    expect(router.state.location.pathname).toBe('/app/school/assessments/1')
    await user.click(screen.getByRole('button', { name: 'Stay and save' }))
    expect(await screen.findByLabelText('Score Synthetic Student 42')).toHaveValue('12')
    expect(api.put).not.toHaveBeenCalled()
  })
})
