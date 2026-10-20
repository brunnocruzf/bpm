// Sincroniza os guias de docs/ (fonte única) para src/docs/content (cópia versionada).
// Uso (a partir do repositório completo): npm run docs:sync
// O build não lê docs/ diretamente: a imagem Docker só recebe apps/web. Por isso a cópia é commitada.
import { copyFileSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const webRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const repoDocs = resolve(webRoot, '..', '..', 'docs')
const contentRoot = resolve(webRoot, 'src', 'docs', 'content')

// [pasta de destino, pasta de origem em docs/, arquivos copiados]
const SOURCES = [
  [
    'integracoes',
    'integracoes',
    [
      'README.md',
      'guia-criar-integracao.md',
      'guia-mapeamentos-e-mock.md',
      'guia-consumir-formulario.md',
      'guia-etapa-integracao.md',
      'guia-operacao-e-suporte.md',
    ],
  ],
  ['adr', 'adr', ['0016-catalogo-de-integracoes-rest.md', '0006-integracao-ax-2012-r3.md']],
]

if (!existsSync(repoDocs)) {
  console.error(`docs/ não encontrado em ${repoDocs}. Rode a partir do repositório completo.`)
  process.exit(1)
}

for (const [dest, src, files] of SOURCES) {
  const to = join(contentRoot, dest)
  mkdirSync(to, { recursive: true })
  // remove cópias que saíram da lista, para não sobrar página órfã
  for (const f of readdirSync(to)) {
    if (f.endsWith('.md') && !files.includes(f)) rmSync(join(to, f))
  }
  for (const f of files) copyFileSync(join(repoDocs, src, f), join(to, f))
  console.log(`${files.length} arquivo(s) de docs/${src} copiado(s) para src/docs/content/${dest}`)
}
