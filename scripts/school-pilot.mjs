import { chromium } from '@playwright/test'
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { randomBytes } from 'node:crypto'

const root = fileURLToPath(new URL('../', import.meta.url))
const workspace = mkdtempSync(join(root, '.school-pilot-'))
const apiBase = 'http://127.0.0.1:18001/api'
const frontend = 'http://127.0.0.1:18000'
const database = join(workspace, 'pilot.sqlite')
writeFileSync(database, '')
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_CONFIG_CACHE: join(workspace, 'unused-config.php'),
  APP_URL: 'http://127.0.0.1:18001', FRONTEND_URL: frontend, APP_KEY: randomBytes(32).toString('hex').slice(0, 32),
  DB_CONNECTION: 'sqlite', DB_URL: '', DB_DATABASE: database, CACHE_STORE: 'array', SESSION_DRIVER: 'database',
  QUEUE_CONNECTION: 'sync', FILESYSTEM_DISK: 'testing', MAIL_MAILER: 'array', MAIL_URL: '',
  DEV_AUTH_ENABLED: 'false', SANCTUM_STATEFUL_DOMAINS: '', VITE_API_URL: apiBase }
const processes = []
let browser
const execute = args => {
  const result = spawnSync('php', args, { cwd: join(root, 'backend'), env, encoding: 'utf8' })
  if (result.status !== 0) throw new Error(`Local pilot preparation failed (${args[0]}). ${result.stderr}`)
}
const ready = async url => {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(url)).ok) return } catch {} await new Promise(r => setTimeout(r, 250)) }
  throw new Error('Local pilot server failed readiness')
}
let actor
const call = async (path, method = 'GET', data, expected = 200) => {
  const response = await fetch(`${apiBase}${path}`, { method, headers: { Accept: 'application/json', Authorization: `Bearer ${actor.token}`, ...(data === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: data === undefined ? undefined : JSON.stringify(data) })
  assert.equal(response.status, expected, `${method} ${path} returned ${response.status}`)
  return response.status === 204 ? null : response.json()
}
try {
  execute(['artisan', 'migrate', '--force'])
  execute(['tests/create-school-pilot-fixtures.php', join(workspace, 'actors.json')])
  const actors = JSON.parse(readFileSync(join(workspace, 'actors.json'), 'utf8'))
  const apiProcess = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=18001', '--no-reload'], { cwd: join(root, 'backend'), env, stdio: 'ignore' })
  const vite = spawn(process.execPath, [join(root, 'frontend/node_modules/vite/bin/vite.js'), '--host', '127.0.0.1', '--port', '18000', '--strictPort'], { cwd: join(root, 'frontend'), env, stdio: 'ignore' })
  processes.push(apiProcess, vite)
  await ready('http://127.0.0.1:18001/up'); await ready(frontend)
  actor = actors.admin
  const year = await call('/school/years', 'POST', { name: 'Synthetic pilot year', starts_on: '2026-01-01', ends_on: '2027-12-31' }, 201)
  const group = await call('/school/groups', 'POST', { academic_year_id: year.id, official_code: 'SYN-PILOT', name: 'Synthetic pilot group', filiere: 'Synthetic', level: '2' }, 201)
  const offerings = []
  for (let i = 1; i <= 2; i++) {
    const module = await call('/school/modules', 'POST', { code: `SYN-P${i}`, name: `Synthetic pilot module ${i}` }, 201)
    const offering = await call(`/school/groups/${group.id}/offerings`, 'POST', { module_id: module.id }, 201)
    await call(`/school/offerings/${offering.id}/teachers`, 'POST', { teacher_id: actors[`teacher${i}`].id }, 201)
    offerings.push(offering.id)
  }
  for (const label of ['student1', 'student2']) await call(`/school/groups/${group.id}/enrollments`, 'POST', { student_id: actors[label].id }, 204)
  const delegate = await call(`/school/groups/${group.id}/delegates`, 'POST', { student_id: actors.student1.id, ends_at: '2027-01-31T00:00:00Z' }, 201)
  await call(`/school/groups/${group.id}/activate`, 'POST', {})
  actor = actors.teacher1
  await call(`/school/offerings/${offerings[0]}/tools/materials`, 'POST', { title: 'Synthetic pilot resource', type: 'link', url: 'https://example.test/resource' }, 201)
  const assignment = await call(`/school/offerings/${offerings[0]}/tools/assignments`, 'POST', { title: 'Synthetic pilot assignment', instructions: 'Synthetic instructions', due_at: '2027-01-01T12:00:00Z' }, 201)
  await call(`/school/offerings/${offerings[0]}/tools/assignments/${assignment.id}/publish`, 'POST', {})
  const assessment = await call(`/school/offerings/${offerings[0]}/assessments`, 'POST', { title: 'Synthetic pilot exam', type: 'exam', assessed_on: '2026-10-07', maximum_score: 20, coefficient: 1 }, 201)
  browser = await chromium.launch()
  const context = await browser.newContext()
  const page = await context.newPage()
  page.on('pageerror', error => console.error('Local pilot browser error type:', error.name))
  page.on('response', response => { const url = new URL(response.url()); if (url.origin === 'http://127.0.0.1:18001' && response.status() >= 400) console.error('Local pilot request failed:', response.request().method(), url.pathname, response.status()) })
  await page.goto(`${frontend}/login`)
  await page.evaluate(token => { sessionStorage.setItem('classlink.token', token); localStorage.setItem('classlink.locale', 'en') }, actor.token)
  await page.goto(`${frontend}/app/school/assessments/${assessment.id}`)
  await page.getByRole('heading', { name: 'Synthetic pilot exam' }).waitFor()
  const templateResponse = await fetch(`${apiBase}/school/assessments/${assessment.id}/template?format=csv`, { headers: { Authorization: `Bearer ${actor.token}`, Accept: 'text/csv' } })
  assert.equal(templateResponse.status, 200)
  const matrix = (await templateResponse.text()).replace(/^\uFEFF/, '').trim().split('\n').map(line => line.replace(/\r$/, '').split(';'))
  for (let i = 1; i < matrix.length; i++) { matrix[i][8] = String(14 + i); matrix[i][9] = 'graded'; matrix[i][10] = `Synthetic private feedback ${i}` }
  const csv = join(workspace, 'grades.csv'); writeFileSync(csv, matrix.map(row => row.join(';')).join('\n'))
  await page.getByLabel('Private file').setInputFiles(csv)
  await page.getByRole('button', { name: 'Preview file', exact: true }).click()
  await page.getByRole('button', { name: 'Confirm draft import', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: /confirm/i }).last().click()
  await page.getByLabel(`Score ${actors.student1.display_name} ${actors.student1.id}`).waitFor()
  await page.getByLabel('Publication summary').fill('Synthetic pilot publication')
  await page.getByRole('button', { name: 'Publish results', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: /confirm/i }).last().click()
  await page.getByRole('button', { name: 'Create correction', exact: true }).waitFor()
  actor = actors.student1
  const results = await call('/school/my-grades')
  assert.equal(results.data.length, 1); assert.equal(Number(results.data[0].score), 15)
  assert.equal(results.data[0].feedback, 'Synthetic private feedback 1')
  actor = actors.student2
  const other = await call('/school/my-grades')
  assert.equal(Number(other.data[0].score), 16); assert.equal(other.data[0].feedback, 'Synthetic private feedback 2')
  actor = actors.teacher1
  await page.getByLabel('Correction reason').fill('Synthetic correction reason')
  await page.getByRole('button', { name: 'Create correction', exact: true }).click()
  const score = page.getByLabel(`Score ${actors.student1.display_name} ${actors.student1.id}`)
  await score.waitFor(); await score.fill('17')
  await page.getByRole('button', { name: 'Save draft', exact: true }).click()
  actor = actors.student1
  assert.equal(Number((await call('/school/my-grades')).data[0].score), 15)
  actor = actors.teacher1
  await page.getByLabel('Publication summary').fill('Synthetic corrected publication')
  await page.getByRole('button', { name: 'Publish results', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: /confirm/i }).last().click()
  await page.getByRole('button', { name: 'Create correction', exact: true }).waitFor()
  actor = actors.student1
  assert.equal(Number((await call('/school/my-grades')).data[0].score), 17)
  const thread = await call('/school/threads', 'POST', { classroom_id: group.id, offering_id: offerings[0], kind: 'private_question', subject: 'Synthetic private question', body: 'Synthetic private message', participant_ids: [actors.teacher1.id] }, 201)
  actor = actors.student2; await call(`/school/threads/${thread.id}`, 'GET', undefined, 404)
  actor = actors.teacher2; await call(`/school/assessments/${assessment.id}`, 'GET', undefined, 403); await call(`/school/threads/${thread.id}`, 'GET', undefined, 404)
  actor = actors.admin
  await call(`/school/delegates/${delegate.id}`, 'DELETE', undefined, 204)
  const overview = await call('/school')
  const teaching = overview.teachers.find(t => t.offering_id === offerings[0])
  await call(`/school/teaching-assignments/${teaching.id}`, 'DELETE', undefined, 204)
  actor = actors.teacher1; await call(`/school/assessments/${assessment.id}`, 'GET', undefined, 403); await call(`/school/threads/${thread.id}`, 'GET', undefined, 404)
  await page.evaluate(token => sessionStorage.setItem('classlink.token', token), actors.student1.token)
  await page.goto(`${frontend}/app/school/grades`)
  await page.getByRole('heading', { name: /Synthetic pilot module 1/ }).waitFor()
  await page.setViewportSize({ width: 360, height: 800 })
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile results must not overflow')
  console.log('Synthetic local pilot passed: shared roster, modules, resource/assignment, browser grade import/publication/correction, private results, communication, revocation and mobile results. No real email or production operation performed.')
} finally {
  if (browser) await browser.close()
  for (const child of processes.reverse()) {
    if (process.platform === 'win32') spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' })
    else child.kill('SIGTERM')
  }
  await new Promise(r => setTimeout(r, 500))
  rmSync(workspace, { recursive: true, force: true })
}
