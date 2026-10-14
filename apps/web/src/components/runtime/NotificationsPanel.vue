<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'

/** Entregas de e-mail (outbox): estados, tentativas, erro sanitizado e reprocessamento de dead-letter. */
interface Msg { id: string; event: string; state: string; recipient: string; recipientName: string | null; recipientHint: string | null; subject: string; attempts: number; maxAttempts: number; provider: string | null; providerMessageId: string | null; errorCode: string | null; error: string | null; processInstanceId: string | null; createdAt: string; sentAt: string | null; nextAttemptAt: string | null }
const notify = useNotify()
const items = ref<Msg[]>([])
const summary = ref<Record<string, number>>({})
const state = ref('failed,retrying')
const TONE: Record<string, string> = { queued: 'neutral', sending: 'amber', sent: 'green', retrying: 'amber', failed: 'red', skipped: 'neutral' }
const LABEL: Record<string, string> = { queued: 'Na fila', sending: 'Enviando', sent: 'Enviada', retrying: 'Nova tentativa', failed: 'Falhou (dead-letter)', skipped: 'Descartada' }

async function load() {
  try {
    const res = await api<{ data: Msg[]; summary: Record<string, number> }>('/admin/notifications', { query: { state: state.value } })
    items.value = res.data
    summary.value = res.summary
  } catch (e) {
    notify.error(e)
  }
}
async function retry(m: Msg) {
  try {
    await api(`/admin/notifications/${m.id}/retry`, { method: 'POST' })
    notify.success('Mensagem reenfileirada.')
    load()
  } catch (e) {
    notify.error(e)
  }
}
onMounted(load)
</script>

<template>
  <section class="tm-panel" style="margin-top:14px">
    <div class="tm-panel-head">
      <div><div class="tm-panel-title">Entregas de e-mail</div><div class="tm-panel-caption"><span v-for="(n, s) in summary" :key="s" style="margin-right:8px">{{ LABEL[s] ?? s }}: {{ n }}</span></div></div>
      <div class="tm-actions">
        <select v-model="state" class="tm-select" aria-label="Filtrar estado" @change="load"><option value="failed,retrying">Falhas e novas tentativas</option><option value="queued,sending">Na fila</option><option value="sent">Enviadas</option><option value="skipped">Descartadas</option><option value="">Todas</option></select>
        <button class="tm-btn small ghost" @click="load"><i class="pi pi-refresh" aria-hidden="true" /></button>
      </div>
    </div>
    <div style="overflow-x:auto"><table class="tm-table">
      <thead><tr><th>Criada</th><th>Tipo</th><th>Destino</th><th>Estado</th><th>Tentativas</th><th>Erro</th><th /></tr></thead>
      <tbody>
        <tr v-if="!items.length"><td colspan="7" class="tm-empty">Nada para o filtro.</td></tr>
        <tr v-for="m in items" :key="m.id">
          <td>{{ fmtDateTime(m.createdAt) }}<div v-if="m.processInstanceId" class="tm-hint"><RouterLink :to="{ name: 'instance', params: { id: m.processInstanceId } }">solicitação</RouterLink></div></td>
          <td>{{ m.event }}<div class="tm-hint">{{ m.subject }}</div></td>
          <td>{{ m.recipientName ?? m.recipient }}<div class="tm-hint">{{ m.recipientHint }}</div></td>
          <td><span class="tm-pill" :class="TONE[m.state]">{{ LABEL[m.state] ?? m.state }}</span><div v-if="m.nextAttemptAt" class="tm-hint">próxima {{ fmtDateTime(m.nextAttemptAt) }}</div></td>
          <td>{{ m.attempts }} / {{ m.maxAttempts }}</td>
          <td><code v-if="m.errorCode">{{ m.errorCode }}</code><div class="tm-hint">{{ m.error }}</div></td>
          <td><button v-if="m.state === 'failed' || m.state === 'retrying'" class="tm-btn small" @click="retry(m)">Reprocessar</button></td>
        </tr>
      </tbody>
    </table></div>
  </section>
</template>

<style scoped>
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
