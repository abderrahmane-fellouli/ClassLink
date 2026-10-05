import { request } from '@playwright/test'

export default async function prepareLocalFixtures() {
  if (!process.env.CLASSLINK_LOCAL_API) return
  const api = new URL(process.env.CLASSLINK_LOCAL_API)
  if (api.protocol !== 'http:' || api.hostname !== '127.0.0.1' || api.username || api.password) {
    throw new Error('Local demo preparation refuses remote or credential URLs')
  }
  const context = await request.newContext({ baseURL: api.origin })
  // Dev auth is unavailable in production. Only known, unverified local demo
  // fixtures may be approved here, through the REAL authenticated admin API.
  // Never automatically approve a Microsoft-verified candidate in this harness.
  const login = await context.post('/api/auth/dev/login', { data: { role: 'admin' } })
  if (!login.ok()) throw new Error('Local demo admin is required for local browser verification')
  const { token } = await login.json()
  const headers = { Authorization: `Bearer ${token}` }
  try {
    const pending = await context.get('/api/admin/users/pending', { headers })
    if (!pending.ok()) throw new Error('Cannot inspect local demo approval state')
    const fixtures = ['zakariyae.chergui@ofppt-edu.ma', 'hamza.bouzid@ofppt-edu.ma']
    for (const candidate of (await pending.json()).data) {
      if (fixtures.includes(candidate.email) && candidate.verification_source === null && candidate.role_candidate === 'teacher' && candidate.is_active) {
        const approval = await context.patch(`/api/admin/users/${candidate.id}`, { headers, data: { role: 'teacher' } })
        if (!approval.ok()) throw new Error('Local demo approval failed')
      }
    }
  } finally {
    await context.post('/api/auth/logout', { headers })
    await context.dispose()
  }
}
