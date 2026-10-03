import Ajv from 'ajv'
import Ajv2020 from 'ajv/dist/2020.js'
import { parse } from 'yaml'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
const root = fileURLToPath(new URL('../', import.meta.url))
for (const [path, url, yaml] of [
  ['render.yaml', 'https://render.com/schema/render.yaml.json', true],
  ['frontend/vercel.json', 'https://openapi.vercel.sh/vercel.json', false],
]) {
  const response = await fetch(url, { signal: AbortSignal.timeout(30000) })
  if (!response.ok) throw new Error('Could not retrieve official schema.')
  const schema = await response.json()
  // Vercel declares draft-04 but contains newer numeric exclusive minima.
  // Normalize boolean draft-04 bounds to draft-07 equivalents, preserving values.
  if (schema.$schema?.includes('draft-04')) {
    delete schema.$schema
    const normalize = node => {
      if (!node || typeof node !== 'object') return
      for (const bound of ['Minimum', 'Maximum']) {
        const key = `exclusive${bound}`
        if (typeof node[key] === 'boolean') {
          const inclusive = bound.toLowerCase()
          if (node[key]) { node[key] = node[inclusive]; delete node[inclusive] }
          else delete node[key]
        }
      }
      for (const child of Object.values(node)) normalize(child)
    }
    normalize(schema)
  }
  const Validator = schema.$schema?.includes('2020-12') ? Ajv2020 : Ajv
  const ajv = new Validator({ strict: false, validateFormats: false, allErrors: true })
  const data = readFileSync(`${root}/${path}`, 'utf8')
  const valid = ajv.validate(schema, yaml ? parse(data) : JSON.parse(data))
  if (!valid) { console.error(ajv.errors); process.exitCode = 1 }
  else console.log(`${path}: official schema OK`)
}
