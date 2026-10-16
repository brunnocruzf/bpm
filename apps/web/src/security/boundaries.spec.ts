/// <reference types="node" />
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Fronteiras de segurança do SPA: o browser nunca chama o Microsoft Graph nem o token endpoint
 * de client credentials, não fala com Flowable/Carbone e não carrega credenciais confidenciais.
 */
// vitest roda a partir de apps/web (no jsdom, import.meta.url não é file://)
const SRC = resolve(process.cwd(), 'src')

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name: string) => {
    const p = join(dir, name)
    return statSync(p).isDirectory() ? files(p) : /\.(ts|vue)$/.test(name) && !name.endsWith('.spec.ts') ? [p] : []
  })
}
const sources = files(SRC).map((f) => ({ f, code: readFileSync(f, 'utf8') }))

describe('fronteiras do SPA', () => {
  it('não chama Microsoft Graph diretamente (sync é exclusivamente do backend)', () => {
    const offenders = sources.filter(({ code }) => /https?:\/\/graph\.microsoft\.com/.test(code)).map(({ f }) => f)
    expect(offenders).toEqual([])
  })

  it('não fala com o Azure Blob Storage nem manipula SAS no browser (anexos passam pela API)', () => {
    for (const { f, code } of sources) {
      expect(code, f).not.toMatch(/https?:\/\/[^\s'"`]*blob\.core\.windows\.net/)
      expect(code, f).not.toMatch(/[?&]sig=|SharedAccessSignature|x-ms-blob-type/)
    }
  })

  it('não usa client credentials nem envia client_secret ao Entra', () => {
    for (const { f, code } of sources) {
      expect(code, f).not.toMatch(/oauth2\/v2\.0\/token/)
      expect(code, f).not.toMatch(/grant_type\s*[:=]\s*['"]client_credentials/)
      expect(code, f).not.toMatch(/acquireTokenByClientCredential|ConfidentialClientApplication/)
    }
  })

  it('não acessa Flowable nem Carbone pelo browser', () => {
    for (const { f, code } of sources) {
      expect(code, f).not.toMatch(/flowable-rest|:4000\/render|\/render\/template/)
    }
  })

  it('fontes e imagens da tela de login são servidas localmente (sem Google Fonts/CDN em runtime)', () => {
    const index = readFileSync(resolve(process.cwd(), 'index.html'), 'utf8')
    for (const { f, code } of [...sources, { f: 'index.html', code: index }]) {
      expect(code, f).not.toMatch(/fonts\.(googleapis|gstatic)\.com|images\.unsplash\.com/)
    }
    const conf = readFileSync(resolve(process.cwd(), 'nginx.conf'), 'utf8')
    expect(/set \$csp "([^"]+)"/.exec(conf)?.[1]).toContain("font-src 'self' data:;")
  })

  it('a senha do SuperAdmin só vai para o endpoint de login (nunca para storage)', () => {
    for (const { f, code } of sources) {
      expect(code, f).not.toMatch(/(sessionStorage|localStorage)\.setItem\([^)]*(password|senha|saPassword)/i)
    }
  })

  it('CSP do nginx não libera Graph/token endpoint e permite apenas blob para o PDF de pré-visualização', () => {
    const conf = readFileSync(resolve(process.cwd(), 'nginx.conf'), 'utf8')
    const csp = /set \$csp "([^"]+)"/.exec(conf)?.[1] ?? ''
    expect(csp).not.toContain('graph.microsoft.com')
    expect(csp).toMatch(/connect-src 'self' https:\/\/login\.microsoftonline\.com;/)
    expect(csp).toMatch(/frame-src 'self' blob: https:\/\/login\.microsoftonline\.com/)
    expect(csp).toContain("object-src 'none'")
    expect(csp).not.toContain("'unsafe-inline' https")
  })
})
