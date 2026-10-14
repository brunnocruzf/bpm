import { defineStore } from 'pinia'
import { palette, updatePreset, updatePrimaryPalette } from '@primeuix/themes'
import { api } from '@/api/http'
import type { Branding } from '@/api/types'
import { derivedTokens } from '@/composables/contrast'

export const DEFAULT_BRANDING: Branding = {
  appName: 'BPM TMSA',
  orgName: 'TMSA',
  tagline: 'Gestão de processos',
  colors: { primary: '#086b77', secondary: '#075762', accent: '#2b6cb0' },
  assets: { main: null, compact: null, favicon: null },
  version: 0,
}

/**
 * Aplica a identidade visual por design tokens (CSS custom properties). Cores de semântica de
 * estado (sucesso/alerta/erro) não são alteradas. `target` permite pré-visualizar num container.
 */
/** Tokens CSS da marca: cores escolhidas + derivados acessíveis (texto sobre a cor, links, hover). */
export function brandTokens(colors: Branding['colors']): Record<string, string> {
  const d = derivedTokens(colors)
  return {
    '--brand': colors.primary,
    '--brand-2': colors.secondary,
    '--accent': colors.accent,
    '--on-brand': d.onPrimary,
    '--on-brand-2': d.onSecondary,
    '--brand-text': d.primaryText,
    '--brand-hover': d.primaryHover,
  }
}

export function applyBranding(b: Branding, target: HTMLElement = document.documentElement) {
  for (const [name, value] of Object.entries(brandTokens(b.colors))) target.style.setProperty(name, value)
  if (target === document.documentElement) {
    const d = derivedTokens(b.colors)
    updatePrimaryPalette(palette(b.colors.primary) as Record<string, string>)
    // componentes PrimeVue (botões, seleção): cor exata da marca e texto com contraste garantido
    updatePreset({ semantic: { colorScheme: { light: { primary: { color: b.colors.primary, contrastColor: d.onPrimary, hoverColor: d.primaryHover, activeColor: d.primaryHover } } } } })
    document.title = `${b.appName} — ${b.tagline || 'Gestão de processos'}`
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (!link) {
      link = document.createElement('link')
      link.rel = 'icon'
      document.head.appendChild(link)
    }
    if (b.assets.favicon) {
      link.removeAttribute('type') // PNG, WebP ou ICO: o navegador identifica pelo conteúdo
      link.href = b.assets.favicon
    } else {
      link.type = 'image/svg+xml'
      link.href = '/favicon.svg'
    }
  }
}

export const useBranding = defineStore('branding', {
  state: () => ({ branding: { ...DEFAULT_BRANDING } as Branding, loaded: false }),
  getters: {
    initials: (s) => s.branding.appName.split(/\s+/).filter(Boolean).slice(0, 1).map((w) => w.slice(0, 3).toUpperCase()).join('') || 'BPM',
  },
  actions: {
    /** @param force ignora cache HTTP (após aplicar/restaurar a marca) */
    async load(force = false) {
      try {
        this.branding = await api<Branding>('/public/branding', { query: force ? { t: Date.now() } : {} })
      } catch {
        this.branding = { ...DEFAULT_BRANDING }
      }
      this.loaded = true
      applyBranding(this.branding)
    },
  },
})
