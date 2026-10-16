import { defineStore } from 'pinia'
import { api, apiBlob } from '@/api/http'
import type { Me, Meta } from '@/api/types'
import { auth } from '@/auth'
import { initialsOf } from '@/composables/format'

export const useSession = defineStore('session', {
  state: () => ({
    me: null as Me | null,
    meta: null as Meta | null,
    loading: false,
    photoUrl: null as string | null,
    photoVersion: null as string | null,
  }),
  getters: {
    can: (state) => (perm: keyof Me['permissions']) => !!state.me?.permissions[perm],
    groupName: (state) => (key: string | null | undefined) => (key ? state.meta?.groups.find((g) => g.key === key)?.name ?? key : '—'),
    initials: (state) => initialsOf(state.me?.name),
  },
  actions: {
    async load() {
      this.loading = true
      try {
        const [me, meta] = await Promise.all([api<Me>('/me'), api<Meta>('/meta')])
        this.me = me
        this.meta = meta
        await this.syncPhoto()
      } finally {
        this.loading = false
      }
    },
    async refreshCounters() {
      if (!this.me) return
      try {
        this.me = await api<Me>('/me')
        await this.syncPhoto()
      } catch {
        /* ignora falha transitória */
      }
    },
    /** Baixa a foto só quando a versão muda (o /me é lido a cada 30s); sem foto, o avatar usa as iniciais. */
    async syncPhoto() {
      const version = this.me?.photo?.version ?? null
      if (version === this.photoVersion) return
      if (!version) { this.clearPhoto(); return }
      try {
        const { blob } = await apiBlob('/me/photo', { v: version })
        if (this.photoUrl) URL.revokeObjectURL(this.photoUrl)
        this.photoUrl = URL.createObjectURL(blob)
        this.photoVersion = version
      } catch {
        this.clearPhoto()
      }
    },
    clearPhoto() {
      if (this.photoUrl) URL.revokeObjectURL(this.photoUrl)
      this.photoUrl = null
      this.photoVersion = null
    },
    async logout() {
      // saída auditada no servidor (revoga a sessão SuperAdmin); falha de rede não impede sair
      try {
        if (auth.isAuthenticated()) await api('/auth/logout', { method: 'POST' })
      } catch {
        /* sessão já expirada/revogada */
      }
      this.me = null
      this.clearPhoto()
      await auth.logout()
    },
  },
})
