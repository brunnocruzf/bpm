import { describe, expect, it } from 'vitest'
import { renderMarkdown, slugify } from './markdown'

const noRoutes = () => null

describe('renderMarkdown (portal de documentação)', () => {
  it('gera ids estáveis e únicos para h2/h3 e ignora h4', () => {
    const doc = renderMarkdown('# Título\n\n## Passo\n\n## Passo\n\n### Ação: `api:<id>`\n\n#### Detalhe', noRoutes)
    expect(doc.title).toBe('Título')
    expect(doc.headings.map((h) => [h.level, h.id])).toEqual([
      [2, 'passo'],
      [2, 'passo-2'],
      [3, 'acao-api-id'],
    ])
    expect(doc.html).toContain('<h2 id="passo">')
    expect(doc.html).not.toContain('<h1')
  })

  it('escapa HTML bruto do arquivo (nada chega ao DOM sem render)', () => {
    const doc = renderMarkdown('texto <script>alert(1)</script> e <b>negrito</b>', noRoutes)
    expect(doc.html).not.toContain('<script')
    expect(doc.html).not.toContain('<b>')
    expect(doc.html).toContain('&lt;script&gt;')
  })

  it('descarta links javascript: e marca links externos com rel seguro', () => {
    const doc = renderMarkdown('[x](javascript:alert(1)) e [AX](https://apidax.tmsa.ind.br)', noRoutes)
    expect(doc.html).not.toContain('href="javascript')
    expect(doc.html).toContain('href="https://apidax.tmsa.ind.br"')
    expect(doc.html).toContain('rel="noopener noreferrer"')
  })

  it('reescreve links entre documentos e mantém o texto de destinos fora do portal', () => {
    const resolve = (href: string) => (href === 'guia-x.md' ? '/docs/integracoes/x' : null)
    const doc = renderMarkdown('[guia](guia-x.md#secao) e [fonte](../../apps/api/fixture.json)', resolve)
    expect(doc.html).toContain('href="/docs/integracoes/x#secao"')
    expect(doc.html).toContain('<span>fonte</span>')
    expect(doc.html).not.toContain('fixture.json"')
  })

  it('transforma blockquote em bloco de destaque (callout)', () => {
    const doc = renderMarkdown('> ⚠ Cuidado com o token.', noRoutes)
    expect(doc.html).toContain('<blockquote class="docs-callout">')
  })
})

describe('imagens dos guias', () => {
  it('troca o caminho relativo pela URL empacotada e mostra só o texto alternativo quando a imagem não existe', () => {
    const resolveImage = (src: string) => (src === 'imagens/01.png' ? '/assets/01-abc.png' : null)
    const doc = renderMarkdown('![Aba Conexões](imagens/01.png)\n\n![Externa](https://exemplo.com/x.png)', noRoutes, resolveImage)
    expect(doc.html).toContain('<img src="/assets/01-abc.png" alt="Aba Conexões" loading="lazy">')
    expect(doc.html).not.toContain('exemplo.com')
    expect(doc.html).toContain('[Externa]')
  })
})

describe('slugify', () => {
  it('remove acentos e pontuação', () => {
    expect(slugify('Consumir em formulários (Form.io)')).toBe('consumir-em-formularios-formio')
  })
})
