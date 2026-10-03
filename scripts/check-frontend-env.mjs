const value = process.env.VITE_API_URL
try {
  const url = new URL(value)
  if (url.protocol !== 'https:' || url.pathname !== '/api' || url.search || url.hash || url.username || url.password || ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) || url.hostname.endsWith('.invalid')) throw new Error()
} catch {
  console.error('Production VITE_API_URL must be an explicit HTTPS API URL ending in /api.')
  process.exit(1)
}
