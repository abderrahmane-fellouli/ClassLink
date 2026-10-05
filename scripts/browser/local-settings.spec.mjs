import { test, expect } from '@playwright/test'

test('teacher invitation settings stay within a narrow viewport (real local API)', async ({ page, request }) => {
  test.skip(!process.env.CLASSLINK_LOCAL_API, 'Requires local API')
  const api = process.env.CLASSLINK_LOCAL_API
  if (!api.startsWith('http://127.0.0.1:')) throw new Error('Local-only test')
  await page.route('**/api/**', async route => {
    const url = new URL(route.request().url())
    await route.fulfill({ response: await route.fetch({ url: api + url.pathname + url.search }) })
  })
  const login = await request.post(`${api}/api/auth/dev/login`, { data: { role: 'teacher' } })
  const { token } = await login.json()
  const headers = { Authorization: `Bearer ${token}` }
  const { data } = await (await request.get(`${api}/api/classes`, { headers })).json()
  await page.goto('/login')
  await page.evaluate(token => sessionStorage.setItem('classlink.token', token), token)
  await page.setViewportSize({ width: 320, height: 800 })
  await page.goto(`/app/classes/${data[0].id}/manage`)
  await page.getByRole('tab').last().click()
  await page.waitForLoadState('networkidle')
  const overflow = await page.evaluate(() => Array.from(document.querySelectorAll('main *')).filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => ({ cls: el.className, text: el.textContent?.slice(0, 80) })).slice(-8))
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), JSON.stringify(overflow)).toBe(true)
  await request.post(`${api}/api/auth/logout`, { headers })
})
