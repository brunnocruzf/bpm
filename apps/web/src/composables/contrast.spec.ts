import { describe, expect, it } from 'vitest'
import { applyBranding, DEFAULT_BRANDING } from '@/stores/branding'
import { checkBrandColors, contrastRatio, derivedTokens, INK } from './contrast'

describe('contraste WCAG', () => {
  it('calcula a razão de contraste como o servidor', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBe(21)
    expect(contrastRatio('#086b77', '#ffffff')).toBeGreaterThan(4.5)
  })

  it('recusa combinação ilegível e aceita a paleta padrão', () => {
    expect(checkBrandColors(DEFAULT_BRANDING.colors).every((r) => r.ok)).toBe(true)
    // tom médio: nem branco nem tinta escura atingem 4,5:1
    const bad = checkBrandColors({ primary: '#808080', secondary: '#075762', accent: '#dddddd' })
    expect(bad.find((r) => r.token === 'primary')?.ok).toBe(false)
    expect(bad.find((r) => r.token === 'accent')?.ok).toBe(false)
  })

  it('aceita primária clara (#EE753F) com texto escuro e tom derivado para links, igual ao servidor', () => {
    const rows = checkBrandColors({ primary: '#EE753F', secondary: '#075762', accent: '#2b6cb0' })
    expect(rows.every((r) => r.ok)).toBe(true)
    const d = derivedTokens({ primary: '#EE753F', secondary: '#075762' })
    expect(d.onPrimary).toBe(INK)
    expect(d.primaryText).toBe('#ab542d')
    expect(contrastRatio('#EE753F', d.onPrimary)).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(d.primaryHover, d.onPrimary)).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(d.primaryText, '#ffffff')).toBeGreaterThanOrEqual(4.5)
  })
})

describe('tokens de marca', () => {
  it('aplica as cores como CSS custom properties em um escopo de pré-visualização sem afetar o documento', () => {
    const scope = document.createElement('div')
    applyBranding({ ...DEFAULT_BRANDING, colors: { primary: '#1d4ed8', secondary: '#1e3a8a', accent: '#b45309' } }, scope)
    expect(scope.style.getPropertyValue('--brand')).toBe('#1d4ed8')
    expect(scope.style.getPropertyValue('--accent')).toBe('#b45309')
    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('')
  })
})
