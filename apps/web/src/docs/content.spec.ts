import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

// Garante que a cópia versionada (src/docs/content) continua igual à fonte em docs/.
// Em ambientes sem o repositório completo (ex.: build Docker), o teste não se aplica.
// `npm test` roda em apps/web, por isso o caminho é relativo ao diretório atual.
const repoDocs = resolve(process.cwd(), '..', '..', 'docs')
const contentDir = resolve(process.cwd(), 'src', 'docs', 'content')

describe('cópia dos guias (npm run docs:sync)', () => {
  it.runIf(existsSync(repoDocs))('é idêntica à fonte em docs/', () => {
    for (const [copy, source] of [
      ['integracoes', 'integracoes'],
      ['adr', 'adr'],
    ]) {
      const files = readdirSync(`${contentDir}/${copy}`)
      expect(files.length).toBeGreaterThan(0)
      for (const file of files) {
        const fromRepo = readFileSync(`${repoDocs}/${source}/${file}`, 'utf8')
        const fromCopy = readFileSync(`${contentDir}/${copy}/${file}`, 'utf8')
        expect(fromCopy, `${copy}/${file} desatualizado: rode npm run docs:sync`).toBe(fromRepo)
      }
    }
  })
})
