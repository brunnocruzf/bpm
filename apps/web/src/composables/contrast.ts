/**
 * Contraste WCAG 2.x — espelha App\Branding\Contrast (o servidor é quem decide).
 * Cores claras são aceitas: o texto sobre a cor vira branco ou tinta escura (o que tiver mais
 * contraste) e links/títulos usam um tom escurecido da primária.
 */
export const WHITE = '#ffffff'
export const INK = '#172230'
const TEXT_MIN = 4.5
const TEXT_TARGET = 5

export function isHex(color: string): boolean {
  return /^#[0-9a-fA-F]{6}$/.test(color)
}

export function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrastRatio(a: string, b: string): number {
  const [la, lb] = [luminance(a), luminance(b)]
  return Math.round(((Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)) * 100) / 100
}

/** Texto (branco ou tinta escura) com maior contraste sobre a cor. */
export function bestForeground(bg: string): { color: string; ratio: number } {
  const white = contrastRatio(bg, WHITE)
  const ink = contrastRatio(bg, INK)
  return white >= ink ? { color: WHITE, ratio: white } : { color: INK, ratio: ink }
}

const hex2 = (n: number) => Math.round(n).toString(16).padStart(2, '0')
const channels = (hex: string) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16))

export function darken(hex: string, t: number): string {
  return '#' + channels(hex).map((c) => hex2(c * (1 - t))).join('')
}

export function lighten(hex: string, t: number): string {
  return '#' + channels(hex).map((c) => hex2(c + (255 - c) * t)).join('')
}

/** Menor escurecimento que atinge o contraste mínimo sobre o fundo (a própria cor, se já atinge). */
export function darkenFor(hex: string, bg = WHITE, min = TEXT_TARGET): string {
  for (let i = 0; i <= 50; i++) {
    const candidate = darken(hex, i / 50)
    if (contrastRatio(candidate, bg) >= min) return candidate
  }
  return '#000000'
}

export interface DerivedTokens { onPrimary: string; onSecondary: string; primaryText: string; primaryHover: string }

export function derivedTokens(colors: { primary: string; secondary: string }): DerivedTokens {
  const onPrimary = bestForeground(colors.primary).color
  return {
    onPrimary,
    onSecondary: bestForeground(colors.secondary).color,
    primaryText: darkenFor(colors.primary),
    // texto claro → hover mais escuro; texto escuro → hover mais claro (mantém o contraste)
    primaryHover: onPrimary === WHITE ? darken(colors.primary, 0.18) : lighten(colors.primary, 0.18),
  }
}

export interface ContrastRow { token: 'primary' | 'secondary' | 'primaryText' | 'accent'; label: string; ratio: number; required: number; ok: boolean; foreground?: string }

/** Mesmas regras do servidor. */
export function checkBrandColors(colors: { primary: string; secondary: string; accent: string }): ContrastRow[] {
  const rows: ContrastRow[] = []
  for (const [token, label] of [['primary', 'Texto sobre a cor (botões) — branco ou escuro, automático'], ['secondary', 'Texto sobre a cor (marca) — branco ou escuro, automático']] as const) {
    const fg = isHex(colors[token]) ? bestForeground(colors[token]) : { color: WHITE, ratio: 0 }
    rows.push({ token, label, ratio: fg.ratio, required: TEXT_MIN, ok: fg.ratio >= TEXT_MIN, foreground: fg.color })
  }
  if (isHex(colors.primary)) {
    const text = darkenFor(colors.primary)
    const ratio = contrastRatio(text, WHITE)
    rows.push({ token: 'primaryText', label: `Links e títulos sobre branco (tom derivado ${text})`, ratio, required: TEXT_MIN, ok: ratio >= TEXT_MIN, foreground: text })
  }
  const accent = isHex(colors.accent) ? contrastRatio(colors.accent, WHITE) : 0
  rows.push({ token: 'accent', label: 'Anel de foco e indicadores sobre branco', ratio: accent, required: 3, ok: accent >= 3 })
  return rows
}
