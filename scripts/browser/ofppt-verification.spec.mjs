import { test, expect } from '@playwright/test'

test('pending teacher uses a server verification receipt without granting a session', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 })
  await page.addInitScript(() => localStorage.setItem('classlink.locale', 'en'))
  const receipt = 'a'.repeat(64)
  await page.route('**/api/auth/microsoft/pending-verification', async route => {
    expect(route.request().postDataJSON()).toEqual({ verification: receipt })
    await route.fulfill({ json: { verification_source: 'microsoft', role_candidate: 'teacher', status: 'pending' } })
  })
  await page.goto(`/pending#verification=${receipt}`)
  await expect(page.getByText('Your OFPPT trainer account has been verified. Teacher access is awaiting administrator approval.')).toBeVisible()
  expect(new URL(page.url()).hash).toBe('')
  expect(await page.evaluate(() => sessionStorage.getItem('classlink.token'))).toBeNull()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})

test('forged or expired pending receipt cannot show Microsoft verification', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('classlink.locale', 'en'))
  await page.route('**/api/auth/microsoft/pending-verification', route => route.fulfill({ status: 404, json: { message: 'Not found' } }))
  await page.goto('/pending#verification=' + 'b'.repeat(64))
  await page.waitForLoadState('networkidle')
  await expect(page.getByText(/Typing an email address does not constitute Microsoft verification/)).toBeVisible()
  await expect(page.getByText(/trainer account has been verified/)).toHaveCount(0)
})

test('admin sees safe candidate details and explicitly approves teacher access', async ({ page }) => {
  let approved = false
  const candidate = { id: 2, display_name: 'Trainer Candidate', email: 'trainer.name@ofppt-edu.ma', role: 'pending', role_candidate: 'teacher', verification_source: 'microsoft', role_locked: false, is_active: true }
  await page.addInitScript(() => {
    localStorage.setItem('classlink.locale', 'en')
    sessionStorage.setItem('classlink.token', 'admin-ui-fixture-not-a-real-token')
  })
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/me')) return route.fulfill({ json: { id: 1, role: 'admin', display_name: 'Test Admin', locale: 'en', is_active: true } })
    if (path.endsWith('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } })
    if (path.endsWith('/admin/users/pending')) return route.fulfill({ json: { data: approved ? [] : [candidate] } })
    if (path.endsWith('/admin/users/2')) {
      expect(route.request().method()).toBe('PATCH')
      expect(route.request().postDataJSON()).toEqual({ role: 'teacher' })
      approved = true
      return route.fulfill({ json: { ...candidate, role: 'teacher', role_locked: true } })
    }
    if (path.endsWith('/admin/users')) return route.fulfill({ json: { data: approved ? [{ ...candidate, role: 'teacher', role_locked: true }] : [], meta: { total: 1 } } })
    return route.fulfill({ json: { data: [] } })
  })
  await page.setViewportSize({ width: 360, height: 900 })
  await page.goto('/app/admin/users')
  await expect(page.getByText('Teacher candidate — approval required')).toBeVisible()
  await expect(page.getByText('Identity verified: Microsoft')).toBeVisible()
  await expect(page.getByText('trainer.name@ofppt-edu.ma')).toBeVisible()
  await page.getByRole('button', { name: 'Teacher', exact: true }).click()
  expect(approved).toBe(false)
  await page.getByRole('dialog').getByRole('button', { name: 'Confirm' }).click()
  await expect(page.getByText('Approved teacher')).toBeVisible()
  expect(approved).toBe(true)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})
