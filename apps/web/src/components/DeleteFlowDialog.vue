<script setup lang="ts">
/**
 * Exclusão de rascunho:
 *  - mode "flow": exclui um fluxo que nunca foi publicado (confirmação digitando o identificador);
 *  - mode "draft": descarta a versão em rascunho de um fluxo publicado (a versão publicada continua).
 * Versões publicadas nunca são apagadas; o servidor recusa e explica.
 */
import { computed, ref } from 'vue'
import Dialog from 'primevue/dialog'
import { api, errorMessage } from '@/api/http'
import { useNotify } from '@/composables/notify'

const props = defineProps<{ flow: { key: string; name: string }; mode: 'flow' | 'draft'; version?: number | null }>()
const emit = defineEmits<{ close: []; deleted: [] }>()
const notify = useNotify()
const typed = ref('')
const understood = ref(false)
const busy = ref(false)
const error = ref('')

const canConfirm = computed(() => (props.mode === 'flow' ? typed.value.trim() === props.flow.key : understood.value))

async function confirm() {
  busy.value = true
  error.value = ''
  try {
    if (props.mode === 'flow') {
      await api(`/flows/${props.flow.key}`, { method: 'DELETE', query: { confirm: typed.value.trim() } })
      notify.success(`Fluxo "${props.flow.name}" excluído.`)
    } else {
      await api(`/definitions/${props.flow.key}/versions/${props.version}`, { method: 'DELETE' })
      notify.success(`Rascunho v${props.version} descartado. A versão publicada continua valendo.`)
    }
    emit('deleted')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :visible="true" modal :header="mode === 'flow' ? 'Excluir fluxo em rascunho' : `Descartar rascunho v${version}`" :style="{ width: 'min(520px, 94vw)' }" @update:visible="(v) => !v && emit('close')">
    <template v-if="mode === 'flow'">
      <p style="margin-top:0">O fluxo <strong>{{ flow.name }}</strong> nunca foi publicado. Ao excluir, saem junto as etapas, rotas e formulários do rascunho. Esta ação não pode ser desfeita (fica registrada na auditoria).</p>
      <div class="tm-field"><label for="del-key">Digite <code>{{ flow.key }}</code> para confirmar</label><input id="del-key" v-model="typed" autocomplete="off" spellcheck="false" @keyup.enter="canConfirm && confirm()" /></div>
    </template>
    <template v-else>
      <p style="margin-top:0">As alterações feitas no rascunho <strong>v{{ version }}</strong> de <strong>{{ flow.name }}</strong> serão perdidas. A versão publicada e as solicitações em andamento não são afetadas.</p>
      <label class="check"><input v-model="understood" type="checkbox" /> <span>Entendo que as alterações do rascunho serão descartadas.</span></label>
    </template>
    <div v-if="error" class="tm-note err" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ error }}</span></div>
    <template #footer>
      <button class="tm-btn" @click="emit('close')">Cancelar</button>
      <button class="tm-btn danger" :disabled="!canConfirm || busy" @click="confirm"><i class="pi pi-trash" aria-hidden="true" /> {{ busy ? 'Excluindo...' : mode === 'flow' ? 'Excluir fluxo' : 'Descartar rascunho' }}</button>
    </template>
  </Dialog>
</template>

<style scoped>
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
