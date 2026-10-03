import { test, expect } from '@playwright/test'

test('public deep links render at 360px without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 })
  for (const path of ['/login', '/privacy', '/denied']) {
    await page.goto(path)
    await expect(page.getByRole('heading').first()).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
})

test('real browser history cannot restore profile after logout (mock API)', async ({ page }) => {
  let loggedOut = false
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/auth/logout')) {
      loggedOut = true
      return route.fulfill({ status: 204 })
    }
    if (loggedOut) return route.fulfill({ status: 401, json: { code: 'unauthenticated' } })
    if (path.endsWith('/me')) return route.fulfill({ json: { id: 1, display_name: 'Browser Fixture', email: 'fixture@example.invalid', initials: 'BF', role: 'student', locale: 'en', is_active: true, last_login_at: null } })
    if (path.endsWith('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } })
    return route.fulfill({ json: { data: [] } })
  })
  await page.goto('/login')
  await page.evaluate(() => sessionStorage.setItem('classlink.token', 'browser-fixture-not-a-real-token'))
  await page.goto('/app/profile')
  await expect(page.getByText('Browser Fixture').first()).toBeVisible()
  await page.getByRole('button', { name: /log ?out|sign out/i }).first().click()
  const dialog = page.getByRole('dialog')
  if (await dialog.isVisible()) await dialog.getByRole('button', { name: /confirm|log ?out|sign out/i }).click()
  await expect(page).toHaveURL(/\/login/)
  expect(loggedOut).toBe(true)
  expect(await page.evaluate(() => sessionStorage.getItem('classlink.token'))).toBeNull()
  await page.goBack()
  await expect(page).toHaveURL(/\/login/)
  await expect(page.getByText('Browser Fixture')).toHaveCount(0)
})
