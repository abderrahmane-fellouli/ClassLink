// Run against an authorized environment only. GETs never trigger maintenance.
const urls = [process.env.CLASSLINK_API_URL && `${process.env.CLASSLINK_API_URL.replace(/\/$/, '')}/ready`, process.env.CLASSLINK_FRONTEND_URL].filter(Boolean)
if (urls.length !== 2) throw new Error('Configure both API and frontend monitoring URLs.')
for (const value of urls) {
  const url = new URL(value)
  if (url.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(url.hostname)) throw new Error('HTTPS required.')
  let healthy = false
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(15000), redirect: 'error' })
      await response.arrayBuffer()
      if (response.status === 200) { healthy = true; break }
    } catch { /* Retry transient failures without logging secret URLs. */ }
    await new Promise(resolve => setTimeout(resolve, 5000))
  }
  console.log(`${url.pathname === '/ready' ? 'API readiness' : 'Frontend'}: ${healthy ? 'OK' : 'FAILED'}`)
  if (!healthy) process.exitCode = 1
}
for (const name of ['DATABASE_EXPIRES_AT', 'STORAGE_EXPIRES_AT', 'ENTRA_SECRET_EXPIRES_AT']) {
  const value = process.env[name]
  if (!value) continue
  const remaining = Date.parse(value) - Date.now()
  if (!Number.isFinite(remaining) || remaining < 7 * 86400000) {
    console.error(`${name}: invalid or expires within 7 days`)
    process.exitCode = 1
  }
}
if (process.env.BREVO_MONITOR_API_KEY) {
  const end = new Date().toISOString().slice(0, 10)
  const start = new Date(Date.now() - 86400000).toISOString().slice(0, 10)
  const response = await fetch(`https://api.brevo.com/v3/smtp/statistics/aggregatedReport?startDate=${start}&endDate=${end}`, {
    headers: { 'api-key': process.env.BREVO_MONITOR_API_KEY }, signal: AbortSignal.timeout(15000),
  })
  if (!response.ok) throw new Error('Brevo monitoring failed; check credentials privately.')
  const stats = await response.json()
  if ((stats.hardBounces ?? 0) + (stats.blocked ?? 0) + (stats.complaints ?? 0) > 0) {
    console.error('Brevo reports hard bounces, blocked mail or complaints. Review dashboard.')
    process.exitCode = 1
  }
}
