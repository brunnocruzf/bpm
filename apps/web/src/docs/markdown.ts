import MarkdownIt, { type Token } from 'markdown-it'

export interface DocHeading {
  level: 2 | 3
  id: string
  text: string
}

export interface RenderedDoc {
  /** texto do primeiro h1 (exibido como título da página; não é repetido no corpo) */
  title: string
  html: string
  /** h2/h3 da página, para o "Nesta página" */
  headings: DocHeading[]
}

/** Resolve o caminho de um link relativo entre arquivos para uma rota do portal; null = fora do portal. */
export type LinkResolver = (href: string) => string | null

/** Resolve o caminho relativo de uma imagem (ex.: "imagens/01.png") para a URL empacotada; null = imagem inexistente. */
export type ImageResolver = (src: string) => string | null

function rewriteImages(children: Token[], resolveImage: ImageResolver): void {
  children.forEach((c) => {
    if (c.type !== 'image') return
    const url = resolveImage(String(c.attrGet('src') ?? ''))
    if (url) {
      c.attrSet('src', url)
      c.attrSet('loading', 'lazy')
      return
    }
    // imagem fora do pacote (ou URL externa, bloqueada pela CSP): mostra só o texto alternativo
    c.type = 'text'
    c.content = `[${c.content}]`
    c.children = null
  })
}

const EXTERNAL = /^(https?:|mailto:)/i

/** Slug para âncoras: sem acentos, minúsculas, só letras, números e hífens. */
export function slugify(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[.'’]/g, '')
    .replace(/[^a-z0-9\s-]/g, ' ')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
}

function plainText(inline: Token | undefined): string {
  if (!inline?.children) return ''
  return inline.children
    .filter((c) => c.type === 'text' || c.type === 'code_inline')
    .map((c) => c.content)
    .join('')
}

function rewriteLinks(children: Token[], resolveLink: LinkResolver): void {
  children.forEach((c, idx) => {
    if (c.type !== 'link_open') return
    const href = String(c.attrGet('href') ?? '')
    const closeIdx = children.findIndex((x, j) => j > idx && x.type === 'link_close')
    if (EXTERNAL.test(href)) {
      c.attrSet('target', '_blank')
      c.attrSet('rel', 'noopener noreferrer')
      return
    }
    if (href.startsWith('#')) return
    const [path, hash] = href.split('#')
    const route = resolveLink(path)
    if (route) {
      c.attrSet('href', hash ? `${route}#${hash}` : route)
      return
    }
    // destino fora do portal (ex.: arquivo do repositório): mantém o texto, sem link quebrado
    c.type = 'span_open'
    c.tag = 'span'
    c.attrs = null
    if (closeIdx >= 0) {
      children[closeIdx].type = 'span_close'
      children[closeIdx].tag = 'span'
    }
  })
}

/**
 * Converte Markdown em HTML para o portal.
 * Segurança: `html: false` faz o markdown-it escapar qualquer HTML do arquivo (nada bruto chega ao DOM);
 * links `javascript:` são descartados pelo próprio markdown-it; links externos abrem em nova aba.
 */
export function renderMarkdown(source: string, resolveLink: LinkResolver, resolveImage: ImageResolver = () => null): RenderedDoc {
  const md = new MarkdownIt({ html: false, linkify: false })
  const tokens = md.parse(source, {})

  let title = ''
  const h1 = tokens.findIndex((t) => t.type === 'heading_open' && t.tag === 'h1')
  if (h1 >= 0) {
    title = plainText(tokens[h1 + 1])
    tokens.splice(h1, 3) // heading_open, inline, heading_close
  }

  const headings: DocHeading[] = []
  const usedIds = new Map<string, number>()

  tokens.forEach((t, i) => {
    if (t.type === 'blockquote_open') t.attrJoin('class', 'docs-callout')
    if (t.type === 'inline' && t.children) {
      rewriteLinks(t.children, resolveLink)
      rewriteImages(t.children, resolveImage)
    }
    if (t.type !== 'heading_open') return
    const level = Number(t.tag.slice(1))
    if (level !== 2 && level !== 3) return
    const text = plainText(tokens[i + 1])
    const base = slugify(text) || 'secao'
    const count = usedIds.get(base) ?? 0
    usedIds.set(base, count + 1)
    const id = count === 0 ? base : `${base}-${count + 1}`
    t.attrSet('id', id)
    headings.push({ level, id, text })
  })

  return { title, html: md.renderer.render(tokens, md.options, {}), headings }
}
