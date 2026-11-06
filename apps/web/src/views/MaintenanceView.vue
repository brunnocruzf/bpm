<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/http'
import type { MaintenanceView as Maintenance } from '@/api/types'
import { useBranding } from '@/stores/branding'

/** Página pública durante a restauração: a API responde sem banco, então aqui só há etapa e percentual. */
const router = useRouter()
const brand = useBranding()
const state = ref<Maintenance | null>(null)
const unreachable = ref(false)

const STEPS = [
  { key: 'stopping', label: 'Parada dos serviços' },
  { key: 'swapping', label: 'Troca dos dados' },
  { key: 'finishing', label: 'Ajustes finais' },
  { key: 'starting', label: 'Reinício dos serviços' },
]
const current = computed(() => STEPS.findIndex((s) => s.key === state.value?.stage))
const inReview = computed(() => state.value?.mode === 'safe')

function stepState(index: number) {
  if (current.value < 0) return 'muted'
  return index < current.value ? 'done' : index === current.value ? 'pending' : 'muted'
}

async function poll() {
  try {
    state.value = await api<Maintenance>('/maintenance')
    unreachable.value = false
    if (state.value.mode === 'normal') router.replace({ path: '/' })
  } catch {
    // a API pode reiniciar durante a troca
    unreachable.value = true
  }
}

let timer: number | undefined
onMounted(() => {
  poll()
  timer = window.setInterval(poll, 3000)
})
onUnmounted(() => window.clearInterval(timer))
</script>

<template>
  <main class="wrap">
    <section class="tm-panel card">
      <div class="tm-panel-body body">
        <div class="brand"><div class="tm-brand-mark" aria-hidden="true">{{ brand.initials }}</div><div class="tm-brand-name">{{ brand.branding.appName }}<span class="tm-brand-sub">{{ brand.branding.tagline }}</span></div></div>
        <template v-if="state?.failed">
          <h2>A restauração precisa de atenção</h2>
          <p class="tm-subtitle" role="alert">{{ state.failed }}</p>
        </template>
        <template v-else-if="inReview">
          <h2>Sistema em revisão</h2>
          <p class="tm-subtitle">Os dados foram restaurados e um administrador está conferindo. O sistema volta em breve, e esta página atualiza sozinha.</p>
          <div class="tm-actions"><RouterLink class="tm-btn small" :to="{ name: 'settings-backups-review' }">Sou administrador: revisar a restauração</RouterLink></div>
        </template>
        <template v-else>
          <h2>Sistema em manutenção</h2>
          <p class="tm-subtitle">Um administrador está restaurando os dados. O sistema volta sozinho, e esta página atualiza automaticamente.</p>
          <div class="tm-timeline" role="status">
            <div v-for="(s, i) in STEPS" :key="s.key" class="tm-tl-item">
              <div class="tm-tl-marker"><div class="tm-tl-dot" :class="{ pending: stepState(i) === 'pending', muted: stepState(i) === 'muted' }" /></div>
              <div class="tm-tl-text"><strong>{{ s.label }}</strong><span>{{ stepState(i) === 'done' ? 'Concluída' : stepState(i) === 'pending' ? 'Em andamento' : 'Aguardando' }}</span></div>
            </div>
          </div>
        </template>
        <div v-if="unreachable" class="tm-hint">Reconectando ao servidor...</div>
      </div>
    </section>
  </main>
</template>

<style scoped>
.wrap { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
.card { width: min(520px, 100%); }
.body { display: grid; gap: 14px; }
.brand { display: flex; align-items: center; gap: 12px; }
.tm-subtitle { margin-top: 6px; }
</style>
