<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import Paginator from 'primevue/paginator'
import { api } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { fmtDateTime } from '@/composables/format'
import SubmissionDialog from '@/components/runtime/SubmissionDialog.vue'

interface Row { id: string; instanceId: string; instanceNumber: string; instanceStatus: string; stageKey: string; stageName: string; authorName: string; revision: number; createdAt: string; summary: { label: string; value: string }[] }

const props = defineProps<{ flowKey: string }>()
const notify = useNotify()
const rows = ref<Row[]>([])
const stages = ref<{ stage_key: string; name: string }[]>([])
const stage = ref('')
const q = ref('')
const total = ref(0)
const page = ref(1)
const flowName = ref('')
const submissionId = ref<string | null>(null)

async function load() {
  try {
    const res = await api<{ data: Row[]; meta: { total: number }; stages: { stage_key: string; name: string }[] }>(`/flows/${props.flowKey}/submissions`, { query: { stage: stage.value, q: q.value, page: page.value } })
    rows.value = res.data
    total.value = res.meta.total
    stages.value = res.stages
  } catch (e) {
    notify.error(e)
  }
}

let timer: number | undefined
watch(q, () => { window.clearTimeout(timer); timer = window.setTimeout(() => { page.value = 1; load() }, 350) })
watch(stage, () => { page.value = 1; load() })
onMounted(async () => {
  load()
  try { flowName.value = (await api<{ flow: { name: string } }>(`/flows/${props.flowKey}`)).flow.name } catch { /* nome opcional */ }
})
</script>

<template>
  <div class="tm-crumbs"><RouterLink to="/">Catálogo de fluxos</RouterLink><span class="sep">›</span><span>{{ flowName }}</span><span class="sep">›</span><span>Respostas</span></div>
  <div class="tm-heading">
    <div><h1>Resultados e respostas</h1><p class="tm-subtitle">Formulários enviados e decisões registradas em cada solicitação, renderizados com o schema exato da época (histórico imutável).</p></div>
  </div>
  <div class="tm-toolbar">
    <div class="tm-toolbar-left">
      <select v-model="stage" class="tm-select" aria-label="Etapa">
        <option value="">Todas as etapas</option>
        <option v-for="s in stages" :key="s.stage_key" :value="s.stage_key">{{ s.name }}</option>
      </select>
      <span class="tm-muted" style="font-size:12px">{{ total }} resposta(s)</span>
    </div>
    <input v-model="q" class="tm-input" placeholder="Buscar por número..." aria-label="Buscar por número" />
  </div>
  <div class="tm-table-wrap">
    <table class="tm-table">
      <thead><tr><th>Solicitação</th><th>Etapa</th><th>Resumo</th><th>Autor</th><th>Enviado em</th></tr></thead>
      <tbody>
        <tr v-if="!rows.length"><td colspan="5"><div class="tm-empty"><div class="tm-empty-icon"><i class="pi pi-file" /></div><strong>Nenhuma resposta</strong>As respostas aparecem aqui à medida que as etapas são concluídas.</div></td></tr>
        <tr v-for="r in rows" :key="r.id" class="clickable" @click="submissionId = r.id">
          <td><RouterLink class="tm-strong" :to="{ name: 'instance', params: { id: r.instanceId } }" @click.stop>{{ r.instanceNumber }}</RouterLink></td>
          <td class="tm-strong">{{ r.stageName }}<div v-if="r.revision > 1" class="tm-flow-sub">revisão {{ r.revision }}</div></td>
          <td><div v-for="s in r.summary" :key="s.label" class="tm-flow-sub" style="margin:0"><b>{{ s.label }}:</b> {{ s.value }}</div></td>
          <td>{{ r.authorName }}</td>
          <td>{{ fmtDateTime(r.createdAt) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
  <Paginator v-if="total > 20" :rows="20" :total-records="total" :first="(page - 1) * 20" @page="(e) => { page = e.page + 1; load() }" />
  <SubmissionDialog v-model:id="submissionId" />
</template>
