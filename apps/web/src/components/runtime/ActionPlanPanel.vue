<script setup lang="ts">
import { computed, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api } from '@/api/http'
import type { SupplierActionInfo } from '@/api/types'
import { fmtDate, fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import AttachmentList from './AttachmentList.vue'

const props = defineProps<{ taskId: string; mode: 'capture' | 'execute'; actions: SupplierActionInfo[]; canWork: boolean }>()
const emit = defineEmits<{ updated: [actions: SupplierActionInfo[]] }>()
const notify = useNotify()

const current = ref<SupplierActionInfo | null>(null)
const notes = ref('')
const files = ref<File[]>([])
const saving = ref(false)

const active = computed(() => props.actions.filter((a) => a.status !== 'superseded'))
const superseded = computed(() => props.actions.filter((a) => a.status === 'superseded'))
const done = computed(() => active.value.filter((a) => a.status === 'done').length)

function open(a: SupplierActionInfo) {
  current.value = a
  notes.value = ''
  files.value = []
}

async function complete() {
  if (!current.value) return
  saving.value = true
  try {
    const ids: string[] = []
    for (const f of files.value) {
      const fd = new FormData()
      fd.append('file', f, f.name)
      fd.append('taskId', props.taskId)
      ids.push((await api<{ id: string }>('/attachments', { method: 'POST', body: fd })).id)
    }
    const res = await api<{ actions: SupplierActionInfo[] }>(`/tasks/${props.taskId}/actions/${current.value.id}/complete`, { method: 'POST', body: { notes: notes.value || null, attachmentIds: ids } })
    emit('updated', res.actions)
    notify.success('Ação concluída e evidências registradas.')
    current.value = null
  } catch (e) {
    notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="tm-panel" style="margin-bottom:14px">
    <div class="tm-panel-head">
      <div>
        <div class="tm-panel-title"><i class="pi pi-list-check" /> Plano de ação {{ mode === 'execute' ? '— execução ação a ação' : '' }}</div>
        <div class="tm-panel-caption">{{ mode === 'execute' ? 'Conclua cada ação com evidência. "Concluir etapa" só é liberado com todas concluídas.' : 'As linhas do repetidor "Plano de ação" viram ações individuais ao concluir esta etapa.' }}</div>
      </div>
      <span v-if="active.length" class="tm-pill" :class="done === active.length ? 'green' : 'amber'">{{ done }}/{{ active.length }} concluídas</span>
    </div>
    <table v-if="active.length" class="tm-table" style="min-width:0">
      <thead><tr><th>Ação</th><th>Responsável</th><th>Prazo</th><th>Local</th><th>Status</th><th /></tr></thead>
      <tbody>
        <tr v-for="a in active" :key="a.id">
          <td class="tm-strong">{{ a.action }}<div v-if="a.notes" class="tm-flow-sub">{{ a.notes }}</div></td>
          <td>{{ a.responsible }}</td>
          <td>{{ fmtDate(a.dueDate) }}</td>
          <td>{{ a.location || '—' }}</td>
          <td>
            <span v-if="a.status === 'done'" class="tm-pill green" v-tooltip="`${a.completedBy} · ${fmtDateTime(a.completedAt)}`">Concluída</span>
            <span v-else class="tm-pill amber">Pendente</span>
          </td>
          <td>
            <button v-if="mode === 'execute' && canWork && a.status === 'pending'" class="tm-btn small primary" @click="open(a)">Concluir ação</button>
            <span v-else-if="a.evidences.length" class="tm-muted">{{ a.evidences.length }} evidência(s)</span>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-else class="tm-empty" style="padding:20px">Nenhuma ação registrada ainda.</div>
    <details v-if="superseded.length" style="padding:8px 14px 12px;font-size:12px">
      <summary class="tm-muted">Planos anteriores substituídos ({{ superseded.length }} ações) — mantidos no histórico</summary>
      <ul><li v-for="a in superseded" :key="a.id">{{ a.action }} · {{ a.responsible }} · {{ fmtDate(a.dueDate) }}</li></ul>
    </details>
  </section>

  <Dialog :visible="!!current" modal :header="`Concluir ação: ${current?.action ?? ''}`" :style="{ width: 'min(520px, 96vw)' }" @update:visible="(v) => { if (!v) current = null }">
    <div class="tm-field"><label>Observações da execução</label><textarea v-model="notes" maxlength="4000" placeholder="O que foi feito, resultados, referências..." /></div>
    <div class="tm-field">
      <label>Evidências (PDF, imagens, planilhas)</label>
      <input type="file" multiple @change="files = Array.from(($event.target as HTMLInputElement).files ?? [])" />
      <div class="tm-hint">Arquivos validados por extensão, tipo real e tamanho; download somente por usuários autorizados.</div>
    </div>
    <AttachmentList v-if="current?.evidences.length" :items="current.evidences" />
    <div class="tm-actions" style="justify-content:flex-end;margin-top:14px">
      <button class="tm-btn" @click="current = null">Cancelar</button>
      <button class="tm-btn primary" :disabled="saving" @click="complete"><i class="pi pi-check" /> Concluir ação</button>
    </div>
  </Dialog>
</template>
