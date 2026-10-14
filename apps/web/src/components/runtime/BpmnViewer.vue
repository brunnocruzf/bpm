<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import 'bpmn-js/dist/assets/diagram-js.css'
import 'bpmn-js/dist/assets/bpmn-js.css'
import 'bpmn-js/dist/assets/bpmn-font/css/bpmn-embedded.css'

const props = withDefaults(defineProps<{ xml: string | null; active?: string[]; completed?: string[]; endElement?: string | null; height?: string }>(), {
  active: () => [], completed: () => [], endElement: null, height: '460px',
})
const emit = defineEmits<{ select: [elementId: string] }>()

const el = ref<HTMLElement>()
const error = ref('')
let viewer: any = null

async function render() {
  if (!el.value || !props.xml) return
  const { default: NavigatedViewer } = await import('bpmn-js/lib/NavigatedViewer')
  viewer?.destroy()
  viewer = new NavigatedViewer({ container: el.value })
  try {
    await viewer.importXML(props.xml)
    const canvas = viewer.get('canvas')
    canvas.zoom('fit-viewport', 'auto')
    for (const id of props.completed) safeMark(canvas, id, 'tm-completed')
    for (const id of props.active) safeMark(canvas, id, 'tm-active')
    if (props.endElement) safeMark(canvas, props.endElement, 'tm-completed')
    viewer.get('eventBus').on('element.click', (e: any) => emit('select', e.element.id))
    error.value = ''
  } catch (e: any) {
    error.value = 'Não foi possível exibir o diagrama: ' + (e?.message ?? e)
  }
}

function safeMark(canvas: any, id: string, cls: string) {
  try { canvas.addMarker(id, cls) } catch { /* elemento inexistente nesta versão */ }
}

onMounted(render)
watch(() => [props.xml, props.active.join(','), props.completed.join(',')], render)
onBeforeUnmount(() => viewer?.destroy())
</script>

<template>
  <div class="bpmn-wrap">
    <div ref="el" class="bpmn-canvas" :style="{ height }" />
    <div v-if="error" class="tm-note err">{{ error }}</div>
  </div>
</template>

<style scoped>
.bpmn-canvas { background-color: #fbfcfd; background-image: radial-gradient(#dfe6eb 0.75px, transparent 0.75px); background-size: 16px 16px; border-radius: 8px; }
:deep(.tm-active .djs-visual > :nth-child(1)) { stroke: #2b6cb0 !important; stroke-width: 3px !important; fill: #eaf3ff !important; }
:deep(.tm-completed .djs-visual > :nth-child(1)) { stroke: #23885b !important; fill: #e8f6ee !important; }
:deep(.tm-error .djs-visual > :nth-child(1)) { stroke: #b54545 !important; stroke-width: 2.5px !important; }
:deep(.tm-selected .djs-visual > :nth-child(1)) { stroke: var(--brand) !important; stroke-width: 3px !important; }
</style>
