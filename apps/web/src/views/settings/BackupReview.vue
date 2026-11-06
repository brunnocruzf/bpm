<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import { api } from '@/api/http'
import type { BackupStatus, SafeModeView } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSession } from '@/stores/session'

/** Modo seguro: conferir os dados antes de reabrir o sistema. Depois de liberar, não dá mais para desfazer. */
const router = useRouter()
const confirm = useConfirm()
const notify = useNotify()
const session = useSession()

const view = ref<SafeModeView | null>(null)
const emails = ref<'discard' | 'send'>('discard')
const working = ref(false)

async function load() {
  try {
    view.value = await api<SafeModeView>('/admin/backups/safe-mode')
  } catch (e) {
    notify.error(e)
  }
}
let timer: number | undefined
onMounted(load)
onUnmounted(() => window.clearInterval(timer))

/** O agente executa em segundo plano: espera o modo normal para voltar à tela de backups. */
function waitForNormal() {
  timer = window.setInterval(async () => {
    try {
      const status = await api<BackupStatus>('/admin/backups/status')
      if (status.mode === 'normal') {
        window.clearInterval(timer)
        await session.refreshCounters()
        router.push({ name: 'settings-backups' })
      }
    } catch {
      /* desfazer passa pela manutenção: o interceptador leva à página própria */
    }
  }, 3000)
}

function release() {
  confirm.require({
    header: 'Liberar o sistema', message: 'Depois de liberar, todos voltam a entrar e não é mais possível desfazer a restauração. Continuar?',
    acceptLabel: 'Liberar', rejectLabel: 'Cancelar',
    accept: async () => {
      working.value = true
      try {
        await api('/admin/backups/safe-mode/release', { method: 'POST', body: { notifications: emails.value } })
        waitForNormal()
      } catch (e) {
        working.value = false
        notify.error(e)
      }
    },
  })
}

function undo() {
  confirm.require({
    header: 'Desfazer restauração', message: 'Os dados voltam a como estavam antes da restauração, e o sistema fica indisponível por alguns instantes. Continuar?',
    acceptLabel: 'Desfazer', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: async () => {
      working.value = true
      try {
        await api('/admin/backups/safe-mode/undo', { method: 'POST', body: {} })
        waitForNormal()
      } catch (e) {
        working.value = false
        notify.error(e)
      }
    },
  })
}
</script>

<template>
  <div class="tm-heading" style="margin-top:0">
    <div>
      <h1>Revisar restauração</h1>
      <p class="tm-subtitle">Confira se os dados estão certos antes de reabrir o sistema. Depois de liberar, não é mais possível desfazer.</p>
    </div>
    <span class="tm-pill amber"><i class="pi pi-pause" aria-hidden="true" /> Aguardando liberação</span>
  </div>

  <div v-if="view && !view.active" class="tm-note"><i class="pi pi-info-circle" aria-hidden="true" /><span>Não há restauração aguardando revisão.</span></div>

  <template v-if="view?.active">
    <div class="tm-note warn" style="margin:0 0 16px"><i class="pi pi-shield" aria-hidden="true" /><span><strong>Os dados voltaram para {{ fmtDateTime(view.restoredTo) }}.</strong> Só administradores entram, e e-mails e integrações estão pausados até a liberação.</span></div>
    <div class="tm-metrics">
      <div class="tm-metric"><div class="tm-metric-label">Comandos do engine a reconciliar</div><div class="tm-metric-value">{{ view.pendingCommands }}</div></div>
      <div class="tm-metric"><div class="tm-metric-label">E-mails pendentes</div><div class="tm-metric-value">{{ view.pendingEmails }}</div></div>
      <div class="tm-metric"><div class="tm-metric-label">Integrações aguardando</div><div class="tm-metric-value">{{ view.integrations.length }}</div></div>
      <div class="tm-metric"><div class="tm-metric-label">Eventos de auditoria guardados</div><div class="tm-metric-value">{{ view.auditEvents }}</div></div>
    </div>
    <div class="grid">
      <section class="tm-panel">
        <div class="tm-panel-head"><div><div class="tm-panel-title">Integrações aguardando</div><div class="tm-panel-caption">Elas podem já ter rodado depois do backup. Confira no sistema de destino antes de liberar.</div></div></div>
        <table v-if="view.integrations.length" class="tm-table">
          <thead><tr><th>Solicitação</th><th>Etapa</th></tr></thead>
          <tbody><tr v-for="(i, n) in view.integrations" :key="n"><td class="tm-strong">{{ i.instance ?? 'Não identificada' }}</td><td>{{ i.stage ?? '—' }}</td></tr></tbody>
        </table>
        <div v-else class="tm-empty"><strong>Nenhuma integração aguardando</strong></div>
      </section>
      <section class="tm-panel">
        <div class="tm-panel-head"><div class="tm-panel-title">Liberar o sistema</div></div>
        <div class="tm-panel-body">
          <fieldset class="choices">
            <legend>E-mails pendentes</legend>
            <label class="choice"><input v-model="emails" type="radio" name="emails" value="discard" /> <span>Descartar os {{ view.pendingEmails }} e-mails</span></label>
            <label class="choice"><input v-model="emails" type="radio" name="emails" value="send" /> <span>Enviar os {{ view.pendingEmails }} e-mails</span></label>
          </fieldset>
          <div class="tm-hint" style="margin-bottom:12px">Alguns podem já ter sido enviados antes da restauração.</div>
          <div class="tm-actions">
            <button class="tm-btn primary" :disabled="working" @click="release"><i class="pi pi-check" aria-hidden="true" /> Liberar o sistema</button>
            <button v-if="view.canUndo" class="tm-btn danger" :disabled="working" @click="undo"><i class="pi pi-undo" aria-hidden="true" /> Desfazer restauração</button>
          </div>
        </div>
      </section>
    </div>
  </template>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
.choices { border: 0; margin: 0; padding: 0; display: grid; gap: 8px; }
.choices legend { font-size: 11px; font-weight: 700; color: #667789; margin-bottom: 6px; padding: 0; }
.choice { display: flex; gap: 8px; align-items: center; font-size: 13px; padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 8px; cursor: pointer; }
.choice:has(input:checked) { border-color: var(--brand); background: var(--brand-softer); }
</style>
