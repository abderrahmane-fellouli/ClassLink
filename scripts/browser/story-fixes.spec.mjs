import { test, expect } from '@playwright/test'

const classroom = { id: 1, name: 'Web', subject: 'Web', group_label: 'A', school_year: '2026', status: 'active', is_read_only: false, join_code: 'ABCDEFGH', join_enabled: true, membership: { status: 'accepted' } }
async function fixture(page, role, responder) {
  await page.addInitScript(() => {
    localStorage.setItem('classlink.locale', 'en')
    sessionStorage.setItem('classlink.token', 'story-browser-fixture')
  })
  await page.route('**/api/**', async route => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace(/^\/api/, '')
    if (path === '/me') return route.fulfill({ json: { id: 1, display_name: 'Story Fixture', role, locale: 'en', is_active: true } })
    if (path === '/notifications') return route.fulfill({ json: { data: [], unread_count: 0 } })
    const result = await responder?.(path, route.request(), url)
    if (result !== undefined) return route.fulfill({ json: result })
    if (path === '/classes') return route.fulfill({ json: { data: [classroom] } })
    if (path === '/classes/1') return route.fulfill({ json: classroom })
    return route.fulfill({ json: { data: [] } })
  })
}

test('invitation code uses native browser clipboard and confirms successful copying', async ({ page, context, browserName }) => {
  if (browserName === 'chromium') await context.grantPermissions(['clipboard-read', 'clipboard-write'])
  await fixture(page, 'teacher')
  await page.setViewportSize({ width: 360, height: 900 })
  await page.goto('/app/classes/1/manage?tab=settings')
  await page.getByRole('button', { name: 'Copy', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Copied', exact: true })).toBeVisible()
  if (browserName === 'chromium') expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('ABCDEFGH')
})

test('student home announcements and complete progression remain accessible on mobile', async ({ page }) => {
  const history = Array.from({ length: 8 }, (_, i) => ({ attempt_id: i + 1, quiz_id: i + 1, quiz_title: `Quiz ${i + 1}`, attempt_no: 1, score: i, max_score: 10, percentage: i * 10, classroom_id: 1 }))
  await fixture(page, 'student', path => {
    if (path === '/me/progress') return { totals: { quizzes_taken: 8, attempts: 8, average_percentage: 35, best_percentage: 70 }, history, trend: { delta_percentage_points: 70 } }
    if (path === '/me/announcements') return { data: [{ id: 1, title: 'Pinned home news', body: 'First', pinned: true, classroom_id: 1, classroom: { name: 'Web' } }, { id: 2, title: 'Latest home news', body: 'Second', pinned: false, classroom_id: 1 }] }
  })
  await page.setViewportSize({ width: 320, height: 900 })
  await page.goto('/app')
  await expect(page.getByText('Pinned home news')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await page.getByRole('link', { name: 'All my results by quiz', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Quiz 1', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Quiz 8', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'View result' })).toHaveCount(8)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})

test('zero participation exposes inactive members in teacher progression', async ({ page }) => {
  await fixture(page, 'teacher', path => path === '/classes/1/progress' ? { totals: { attempts: 0, students: 1, quizzes: 0, class_average: 0, participation_rate: 0 }, most_missed: [], inactive_student_ids: [3], inactive_students: [{ id: 3, display_name: 'Inactive member' }] } : undefined)
  await page.goto('/app/progression')
  await expect(page.getByText('Inactive member')).toBeVisible()
  await expect(page.getByText('0%', { exact: true })).toBeVisible()
})

for (const terminal of ['done', 'failed']) {
  test(`AI lifecycle tracks processing to ${terminal} without stalling`, async ({ page }) => {
    test.slow()
    let polls = 0
    await fixture(page, 'teacher', path => {
      if (path === '/classes/1/ai/generate') return { id: 9, target: 'quiz', status: 'queued' }
      if (path === '/ai/jobs/9') return { id: 9, target: 'quiz', status: ++polls === 1 ? 'processing' : terminal }
    })
    await page.goto('/app/classes/1/manage?tab=quizzes')
    await page.locator('input[type=file][accept="application/pdf"]').setInputFiles({ name: 'course.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-fixture') })
    await page.getByRole('button', { name: 'Start generation', exact: true }).click()
    if (terminal === 'done') await expect(page.getByText('Completed', { exact: true }).first()).toBeVisible({ timeout: 15000 })
    else await expect(page.getByRole('link', { name: 'Continue with manual creation' })).toBeVisible({ timeout: 15000 })
    expect(polls).toBe(2)
  })
}

test('audit category and date filters use the corrected API contract', async ({ page }) => {
  const requests = []
  await fixture(page, 'admin', (path, request, url) => {
    if (path === '/admin/audit-logs') {
      requests.push(url.searchParams.toString())
      return { data: [], meta: { total: 0 } }
    }
  })
  await page.goto('/app/admin/audit')
  await expect(page.getByRole('combobox', { name: 'All actions' })).toBeVisible()
  await page.getByRole('combobox', { name: 'All actions' }).selectOption('class')
  await page.locator('input[type=date]').last().fill('2026-10-01')
  await expect.poll(() => requests.some(query => query.includes('action=class') && query.includes('to=2026-10-01')), { timeout: 20000 }).toBe(true)
})
