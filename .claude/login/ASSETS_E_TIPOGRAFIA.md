# BPM TMSA — imagens, fontes e tipografia do protótipo

Este documento acompanha `index.html` e registra os recursos visuais usados no protótipo da tela de login, suas origens e recomendações para a implementação de produção.

## 1. Imagens

### 1.1 Foto do painel visual

- **Arquivo local:** [`assets/equipe-colaboracao-unsplash.jpg`](assets/equipe-colaboracao-unsplash.jpg)
- **Descrição:** equipe diversa colaborando em uma mesa de trabalho em um escritório moderno.
- **Fotógrafo:** [Vitaly Gariev](https://unsplash.com/@silverkblack).
- **Página original da foto:** [Unsplash — A diverse group of people collaborating around a table in a modern office](https://unsplash.com/photos/diverse-team-collaborating-in-modern-office-xsQPWxhp2tc).
- **Licença indicada na página da foto:** [Unsplash License](https://unsplash.com/license). O protótipo também mostra crédito discreto sobre a fotografia.
- **Uso no layout:** imagem de fundo do painel à direita em desktop; no mobile, faixa visual superior. Há camadas de contraste sobre a foto para manter a legibilidade do texto.

Antes de publicar o produto, confira novamente os termos vigentes da fonte da foto e substitua-a por fotografia institucional licenciada se a equipe de marca/jurídico exigir.

### 1.2 Marca TMSA

- **Arquivo local:** [`assets/logo-tmsa-recortado.png`](assets/logo-tmsa-recortado.png)
- **Origem:** recorte do wordmark visível na imagem de referência fornecida pelo solicitante.
- **Observação importante:** o arquivo foi extraído de uma captura/imagem, não é o master original. A resolução e o recorte podem não ser adequados para lançamento. Substituir pelo logo oficial em SVG ou PNG de alta resolução fornecido pela TMSA antes de produção. Se for SVG, sanitizar o arquivo antes de servir.
- A assinatura “Tecnologia em Movimentação” é texto HTML no protótipo (não faz parte do PNG recortado), para manter a tagline nítida e acessível.
- **Uso no layout:** lockup central no painel de acesso, com texto alternativo acessível.

### 1.3 Ícone Microsoft

O ícone colorido ao lado do botão “Entrar com Microsoft 365” é composto por quatro quadrados SVG/CSS inline no próprio `index.html`; não é uma imagem raster externa. É apenas elemento visual de um protótipo. Na implementação real, adotar os assets e requisitos de marca vigentes da Microsoft.

## 2. Fontes

O protótipo importa as famílias do Google Fonts pela folha CSS remota. Se a rede não estiver disponível, o navegador usa as fontes de fallback definidas no CSS.

| Família | Uso | Pesos carregados | Fonte |
|---|---|---|---|
| **Manrope** | Títulos e mensagens de destaque | 500, 600, 700, 800 | [Google Fonts — Manrope](https://fonts.google.com/specimen/Manrope) |
| **Inter** | Texto de interface, botão, instruções, links e microcopy | 400, 500, 600, 700 | [Google Fonts — Inter](https://fonts.google.com/specimen/Inter) |

As famílias são disponibilizadas sob a [SIL Open Font License (OFL)](https://openfontlicense.org/). Para produção, confirmar os arquivos/licenças da versão efetivamente selecionada e, se a aplicação precisar operar sem dependência externa, baixar os WOFF2 aprovados e servi-los localmente; não copiar fontes comerciais sem licença.

**Fallbacks definidos no protótipo:**

```css
font-family: "Inter", "Segoe UI", Arial, sans-serif;
font-family: "Manrope", "Inter", "Segoe UI", sans-serif;
```

## 3. Escala tipográfica

A hierarquia usa poucos níveis para manter o login simples e de leitura rápida:

| Elemento | Escala desktop | Escala adaptativa/mobile | Peso sugerido | Uso |
|---|---:|---:|---:|---|
| Título do painel de acesso | 30–38 px | 28–35 px | Manrope 700 | “Bem-vindo ao BPM TMSA” |
| Título do painel visual | 36–64 px | 25–38 px | Manrope 700 | Mensagem institucional |
| Texto introdutório | 15 px | 14 px | Inter 400 | Contexto breve de acesso |
| Ação principal | 14 px | 14 px | Inter 600 | Entrar com Microsoft 365 |
| Texto auxiliar/segurança | 12–13 px | 12–13 px | Inter 400/500 | Instruções e ajuda |
| Eyebrow, crédito e rodapé | 10–11 px | 8–10 px | Inter 600/700 | Rótulos e metadados |

O CSS usa line-height confortável (cerca de 1.5–1.7 em textos corridos), hierarquia por tamanho/peso e títulos com tracking ligeiramente negativo. Manter o texto legível com zoom de navegador e sem depender apenas de cor para indicar estado.

## 4. Paleta aproximada

A paleta foi escolhida para dialogar com o laranja/preto visível na marca TMSA e com uma linguagem corporativa contemporânea. Cores são aproximações do protótipo; confirmar tokens oficiais com a equipe de marca antes de implementar white-label/branding.

| Token | Valor | Aplicação |
|---|---|---|
| `--brand-orange` | `#F36F21` | Destaques e elemento de marca |
| `--brand-orange-dark` | `#D95B12` | Hover/ação textual |
| `--ink` | `#191A1D` | Texto principal |
| `--muted` | `#686D75` | Texto auxiliar |
| `--line` | `#E6E7E9` | Bordas e divisores |
| `--surface` | `#FFFFFF` | Painel de acesso |
| `--canvas` | `#F7F7F6` | Fundo neutro |
| `--hero-deep` | `#142C32` | Base do tratamento do painel visual |
| `--focus` | `#175CD3` | Indicador de foco acessível |

## 5. Diretrizes de uso e produção

- O layout é responsivo; em telas menores, a imagem passa para uma faixa superior e o acesso permanece em primeiro plano.
- O botão de login demonstra a hierarquia visual. **Ele não autentica de verdade:** o JavaScript mostra uma mensagem informando que o protótipo precisa ser conectado ao Microsoft Entra ID.
- Não incluir login local por senha enquanto a decisão do produto for autenticação corporativa Entra ID.
- Não incorporar secrets, tokens ou configuração confidencial de Entra em HTML/JavaScript. Na aplicação real, integrar MSAL/OIDC conforme a arquitetura aprovada, com Authorization Code + PKCE.
- Personalizar o canal de suporte, política de privacidade e URLs institucionais antes de publicar; os links atuais exibem mensagem de protótipo.
- No produto final, implementar logo, cores, estados de foco/erro/carregamento e parâmetros institucionais via configuração de branding administrável, com validação de contraste e acessibilidade.

## 6. Arquivos incluídos

- `index.html` — protótipo responsivo e interativo da tela de login.
- `assets/equipe-colaboracao-unsplash.jpg` — fotografia local usada no painel visual.
- `assets/logo-tmsa-recortado.png` — recorte do wordmark exibido na referência do solicitante.
- `ASSETS_E_TIPOGRAFIA.md` — este inventário de recursos, fontes e especificação tipográfica.
