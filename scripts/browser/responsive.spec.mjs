import { test, expect } from '@playwright/test'

const widths = [320, 360, 375, 390, 430, 768, 1024, 1280, 1440, 1920]

async function fixture(page, role) {
  await page.addInitScript(() => {
    sessionStorage.setItem('classlink.token', 'responsive-fixture')
    localStorage.setItem('classlink.locale', 'en')
  })
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/me')) return route.fulfill({ json: { id: 1, display_name: 'Responsive Fixture', initials: 'RF', email: 'fixture@example.invalid', role, locale: 'en', is_active: true } })
    if (path.endsWith('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } })
    return route.fulfill({ json: { data: [], totals: {}, history: [] } })
  })
}

for (const role of ['student', 'teacher', 'admin']) {
  test(`${role}: navigation, language and content fit all supported viewport widths`, async ({ page }) => {
    test.slow()
    await fixture(page, role)
    await page.goto('/app/profile')
    await expect(page.getByText('Responsive Fixture').first()).toBeVisible()
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 })
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${role} at ${width}px`).toBe(true)
      const language = page.getByRole('combobox', { name: 'Interface language', exact: true }).filter({ visible: true }).first()
      await expect(language).toBeVisible()
      await language.selectOption('fr')
      await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
      await page.getByRole('combobox', { name: /Langue/, exact: false }).filter({ visible: true }).first().selectOption('en')
      if (width < 768) {
        const menu = page.getByRole('button', { name: 'Navigation menu' })
        await menu.click()
        const dialog = page.getByRole('dialog')
        await expect(dialog.getByRole('combobox', { name: 'Interface language' })).toBeVisible()
        await expect(dialog.getByRole('button', { name: 'Sign out' })).toBeVisible()
        expect(await dialog.evaluate(el => el.getBoundingClientRect().right <= innerWidth)).toBe(true)
        await page.keyboard.press('Escape')
        await expect(dialog).not.toBeVisible()
        await expect(menu).toBeFocused()
      } else {
        expect(await page.locator('main').evaluate(el => el.getBoundingClientRect().width)).toBeLessThanOrEqual(1200)
        expect(await page.locator('main p.text-xs').first().evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeLessThan(16)
      }
    }
  })
}

test('public navbar language and login fit narrow phones', async ({ page }) => {
  test.slow()
  for (const width of widths) {
    await page.setViewportSize({ width, height: 900 })
    await page.goto('/')
    await page.getByRole('combobox', { name: /language|Langue/ }).selectOption('fr')
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `home at ${width}px`).toBe(true)
  }
})

// Real local API verification, separate from responsive HTTP fixtures.
test('local demo roles open their actual application routes without server/render errors', async ({ page, request }) => {
  test.skip(!process.env.CLASSLINK_LOCAL_API, 'Requires running local Laravel with demo data')
  const api = process.env.CLASSLINK_LOCAL_API
  if (!api.startsWith('http://127.0.0.1:')) throw new Error('Local-only test; remote hosts refused')
  test.setTimeout(300_000)
  await page.route('**/api/**', async route => {
    const target = new URL(route.request().url())
    const response = await route.fetch({ url: api + target.pathname + target.search })
    await route.fulfill({ response })
  })
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${response.status()} ${response.url()}`) })
  await page.goto('/login')
  for (const role of ['student', 'teacher', 'admin']) {
    const login = await request.post(`${api}/api/auth/dev/login`, { data: { role } })
    expect(login.ok()).toBe(true)
    const { token } = await login.json()
    const identity = await request.get(`${api}/api/me`, { headers: { Authorization: `Bearer ${token}` } })
    expect(identity.ok()).toBe(true)
    expect((await identity.json()).role).toBe(role)
    await page.evaluate(() => sessionStorage.clear())
    await page.goto('/login')
    await page.evaluate(token => sessionStorage.setItem('classlink.token', token), token)
    const paths = role === 'student'
      ? ['/app', '/app/classes', '/app/deadlines', '/app/partners', '/app/join', '/app/profile']
      : role === 'teacher'
        ? ['/app', '/app/classes', '/app/classes/new', '/app/requests', '/app/progression', '/app/profile']
        : ['/app/admin', '/app/admin/users', '/app/admin/classes', '/app/admin/ai', '/app/admin/audit', '/app/profile']
    if (role !== 'admin') {
      const headers = { Authorization: `Bearer ${token}` }
      const classes = (await (await request.get(`${api}/api/classes`, { headers })).json()).data
      const classroom = classes.find(item => item.status === 'active')
      if (classroom) {
        paths.push(role === 'teacher' ? `/app/classes/${classroom.id}/manage` : `/app/classes/${classroom.id}`)
        const quizzes = (await (await request.get(`${api}/api/classes/${classroom.id}/quizzes`, { headers })).json()).data
        const assignments = (await (await request.get(`${api}/api/classes/${classroom.id}/assignments`, { headers })).json()).data
        const decks = (await (await request.get(`${api}/api/classes/${classroom.id}/flashcards`, { headers })).json()).data
        if (decks.length) paths.push(`/app/flashcard-decks/${decks[0].id}`)
        if (role === 'teacher') {
          paths.push(`/app/classes/${classroom.id}/manage/quizzes/new`)
          if (quizzes.length) paths.push(`/app/classes/${classroom.id}/manage/quizzes/${quizzes[0].id}/results`)
          if (assignments.length) paths.push(`/app/classes/${classroom.id}/manage/assignments/${assignments[0].id}/grade`)
        } else {
          if (quizzes.length) paths.push(`/app/classes/${classroom.id}/quizzes/${quizzes[0].id}`)
          if (assignments.length) paths.push(`/app/assignments/${assignments[0].id}`)
        }
      }
    }
    for (const width of [360, 1440]) {
      await page.setViewportSize({ width, height: 900 })
      for (const path of paths) {
        await page.goto(path)
        await expect(page.locator('main h1'), `${role} ${path}, final URL ${page.url()}`).toBeVisible()
        await page.waitForLoadState('networkidle')
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${role} ${path} ${width}px`).toBe(true)
        if (path === '/app' || path === '/app/admin') {
          await page.screenshot({ path: test.info().outputPath(`${role}-${width}.png`), fullPage: true })
        }
        const tabs = page.getByRole('tab')
        for (let index = 0; index < await tabs.count(); index++) {
          await tabs.nth(index).click()
          await page.waitForLoadState('networkidle')
          const overflow = await page.evaluate(() => Array.from(document.querySelectorAll('main *')).filter(el => el.getBoundingClientRect().right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed').map(el => ({ tag: el.tagName, cls: el.className, text: el.textContent?.slice(0, 80) })).slice(-8))
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${role} ${path} tab ${index} ${width}px ${JSON.stringify(overflow)}`).toBe(true)
        }
      }
    }
    await request.post(`${api}/api/auth/logout`, { headers: { Authorization: `Bearer ${token}` } })
  }
  expect(errors).toEqual([])
})
