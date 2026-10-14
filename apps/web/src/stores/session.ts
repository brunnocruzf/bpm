import { defineStore } from 'pinia'
import { api } from '@/api/http'
import type { Me, Meta } from '@/api/types'
import { auth } from '@/auth'

export const useSession = defineStore('session', {
  state: () => ({
    me: null as Me | null,
    meta: null as Meta | null,
    loading: false,
  }),
  getters: {
    can: (state) => (perm: keyof Me['permissions']) => !!state.me?.permissions[perm],
    groupName: (state) => (key: string | null | undefined) => (key ? state.meta?.groups.find((g) => g.key === key)?.name ?? key : '—'),
    initials: (state) => (state.me?.name ?? '?').split(/\s+/).filter((w) => /^[A-Za-zÀ-ú]/.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase(),
  },
  actions: {
    async load() {
      this.loading = true
      try {
        const [me, meta] = await Promise.all([api<Me>('/me'), api<Meta>('/meta')])
        this.me = me
        this.meta = meta
      } finally {
        this.loading = false
      }
    },
    async refreshCounters() {
      if (!this.me) return
      try {
        this.me = await api<Me>('/me')
      } catch {
        /* ignora falha transitória */
      }
    },
    async logout() {
      // saída auditada no servidor (revoga a sessão SuperAdmin); falha de rede não impede sair
      try {
        if (auth.isAuthenticated()) await api('/auth/logout', { method: 'POST' })
      } catch {
        /* sessão já expirada/revogada */
      }
      this.me = null
      await auth.logout()
    },
  },
})
