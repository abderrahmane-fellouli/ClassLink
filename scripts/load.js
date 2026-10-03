import http from 'k6/http'
import { check, sleep } from 'k6'
const base = __ENV.CLASSLINK_API_URL
const tokens = JSON.parse(__ENV.CLASSLINK_LOAD_TOKENS || '[]')
if (__ENV.LOAD_TEST_APPROVED !== 'true' || !base || new Set(tokens).size < 200) throw new Error('Approve staging load test and supply 200 distinct synthetic user tokens.')
export const options = {
  stages: [{ duration: '30s', target: 50 }, { duration: '30s', target: 200 }, { duration: '2m', target: 200 }, { duration: '15s', target: 0 }],
  thresholds: { http_req_duration: ['p(95)<3000'], http_req_failed: ['rate<0.01'], checks: ['rate>0.99'] },
}
export default function () {
  for (const path of ['/api/me', '/api/classes', '/api/notifications']) {
    const response = http.get(`${base}${path}`, { headers: { Authorization: `Bearer ${tokens[(__VU - 1) % tokens.length]}`, Accept: 'application/json' }, tags: { endpoint: path } })
    check(response, { 'authorized read succeeds': r => r.status === 200 })
  }
  sleep(3)
}
