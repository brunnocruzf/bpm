<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import 'bpmn-js/dist/assets/diagram-js.css'
import 'bpmn-js/dist/assets/bpmn-js.css'
import 'bpmn-js/dist/assets/bpmn-font/css/bpmn-embedded.css'
import { api } from '@/api/http'
import type { ValidationProblem, Version } from '@/api/types'
import { useNotify } from '@/composables/notify'

const props = defineProps<{ flowKey: string; version: Version; editable: boolean; problems: ValidationProblem[]; selectedKey: string | null }>()
const emit = defineEmits<{ select: [stageKey: string]; imported: [version: Version] }>()
const notify = useNotify()

const el = ref<HTMLElement>()
const fileInput = ref<HTMLInputElement>()
const loading = ref(false)
const modified = ref(false)
let modeler: any = null

const base = () => `/definitions/${props.flowKey}/versions/${props.version.version}`

async function load() {
  if (!el.value) return
  loading.value = true
  try {
    const xml = await api<string>(`${base()}/bpmn`, { raw: true })
    const Ctor = props.editable ? (await import('bpmn-js/lib/Modeler')).default : (await import('bpmn-js/lib/NavigatedViewer')).default
    modeler?.destroy()
    modeler = new Ctor({ container: el.value })
    await modeler.importXML(xml)
    modeler.get('canvas').zoom('fit-viewport', 'auto')
    applyMarkers()
    const bus = modeler.get('eventBus')
    bus.on('element.click', (e: any) => {
      const stage = props.version.stages.find((s) => s.elementId === e.element.id)
      if (stage) emit('select', stage.key)
    })
    if (props.editable) bus.on('commandStack.changed', () => { modified.value = true })
    modified.value = false
  } catch (e) {
    notify.error(e, 'Falha ao carregar o diagrama')
  } finally {
    loading.value = false
  }
}

function applyMarkers() {
  if (!modeler) return
  const canvas = modeler.get('canvas')
  const registry = modeler.get('elementRegistry')
  for (const s of props.version.stages) {
    if (!registry.get(s.elementId)) continue
    canvas.removeMarker(s.elementId, 'tm-error')
    canvas.removeMarker(s.elementId, 'tm-selected')
    if (props.problems.some((p) => p.stageKey === s.key && p.severity === 'error')) canvas.addMarker(s.elementId, 'tm-error')
    if (s.key === props.selectedKey) canvas.addMarker(s.elementId, 'tm-selected')
  }
}

async function exportXml() {
  const xml = modeler && modified.value ? (await modeler.saveXML({ format: true })).xml : await api<string>(`${base()}/bpmn`, { raw: true })
  const blob = new Blob([xml], { type: 'application/xml' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${props.flowKey}-v${props.version.version}.bpmn`
  a.click()
  URL.revokeObjectURL(a.href)
}

async function importXml(xml: string) {
  try {
    const res = await api<{ summary: { created: string[]; removed: string[]; updated: number }; version: Version }>(`${base()}/bpmn/import`, { method: 'POST', body: { xml } })
    emit('imported', res.version)
    const s = res.summary
    notify.success(`Diagrama aplicado: ${s.updated} etapa(s) atualizada(s), ${s.created.length} criada(s), ${s.removed.length} removida(s). Formulários foram preservados pelo ID BPMN.`)
  } catch (e) {
    notify.error(e, 'BPMN não aceito')
  }
}

async function applyEdits() {
  const { xml } = await modeler.saveXML({ format: true })
  await importXml(xml)
}

async function onFile(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  await importXml(await file.text())
  ;(ev.target as HTMLInputElement).value = ''
}

onMounted(load)
watch(() => [props.version.lockVersion, props.version.version, props.editable], load)
watch(() => [props.problems, props.selectedKey], applyMarkers, { deep: true })
onBeforeUnmount(() => modeler?.destroy())
</script>

<template>
  <section class="tm-panel">
    <div class="tm-panel-head">
      <div>
        <div class="tm-panel-title">Diagrama BPMN 2.0 · v{{ version.version }} {{ version.status === 'draft' ? '(rascunho)' : '' }}</div>
        <div class="tm-panel-caption">
          Gerado a partir das etapas, com raias por grupo. Clique numa tarefa para abrir a etapa e o formulário vinculados.
          <template v-if="editable"> Analistas podem editar aqui e aplicar ao rascunho (somente o subconjunto suportado).</template>
        </div>
      </div>
      <div class="tm-actions">
        <button class="tm-btn small" @click="exportXml"><i class="pi pi-download" /> Exportar XML</button>
        <template v-if="editable">
          <button class="tm-btn small" @click="fileInput?.click()"><i class="pi pi-upload" /> Importar arquivo</button>
          <button class="tm-btn small primary" :disabled="!modified" @click="applyEdits"><i class="pi pi-check" /> Aplicar edições ao rascunho</button>
          <input ref="fileInput" type="file" accept=".bpmn,.xml" hidden @change="onFile" />
        </template>
        <button class="tm-btn small icon" v-tooltip="'Recarregar'" aria-label="Recarregar" @click="load"><i class="pi pi-refresh" /></button>
      </div>
    </div>
    <div ref="el" class="bpmn-canvas" />
    <div class="tm-note" style="margin:0 14px 14px">
      <i class="pi pi-link" />
      <span><strong>Vínculo visual:</strong> cada tarefa humana abre sua etapa e formulário. Elementos fora do subconjunto (scripts, subprocessos, listeners, condições livres) são recusados na importação com mensagem explicativa.</span>
    </div>
  </section>
</template>

<style scoped>
.bpmn-canvas { height: 62vh; min-height: 420px; background-color: #fbfcfd; background-image: radial-gradient(#dfe6eb 0.75px, transparent 0.75px); background-size: 16px 16px; }
:deep(.tm-error .djs-visual > :nth-child(1)) { stroke: #b54545 !important; stroke-width: 2.5px !important; }
:deep(.tm-selected .djs-visual > :nth-child(1)) { stroke: var(--brand) !important; stroke-width: 3px !important; fill: var(--brand-softer) !important; }
</style>
