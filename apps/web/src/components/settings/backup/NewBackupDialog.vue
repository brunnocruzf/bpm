<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api } from '@/api/http'
import type { BackupScope } from '@/api/types'
import { SCOPE_HINTS, SCOPE_LABELS } from '@/composables/backup'
import { useNotify } from '@/composables/notify'

const props = defineProps<{ visible: boolean; azureAvailable: boolean }>()
const emit = defineEmits<{ 'update:visible': [boolean]; done: [] }>()
const notify = useNotify()

const scope = ref<BackupScope>('database')
const azure = ref(false)
const verify = ref(false)
const saving = ref(false)
const scopes = Object.keys(SCOPE_LABELS) as BackupScope[]

watch(() => props.visible, (open) => {
  if (!open) return
  scope.value = 'database'
  azure.value = props.azureAvailable
  verify.value = false
})

async function submit() {
  saving.value = true
  try {
    await api('/admin/backups', { method: 'POST', body: { scope: scope.value, azure: azure.value, verify: verify.value } })
    notify.success('Backup iniciado. Acompanhe o andamento nesta página.')
    emit('update:visible', false)
    emit('done')
  } catch (e) {
    notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog :visible="visible" modal header="Fazer backup agora" :style="{ width: 'min(480px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form @submit.prevent="submit">
      <fieldset class="scopes">
        <legend class="tm-sr-only">O que copiar</legend>
        <label v-for="s in scopes" :key="s" class="scope"><input v-model="scope" type="radio" name="scope" :value="s" /> <span><strong>{{ SCOPE_LABELS[s] }}</strong><small>{{ SCOPE_HINTS[s] }}</small></span></label>
      </fieldset>
      <label v-if="azureAvailable" class="check"><input v-model="azure" type="checkbox" /> <span>Enviar uma cópia ao Azure</span></label>
      <label class="check"><input v-model="verify" type="checkbox" /> <span>Testar a carga dos bancos ao criar (leva mais tempo)</span></label>
      <div class="tm-actions" style="justify-content:flex-end;margin-top:12px">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn primary" type="submit" :disabled="saving">Iniciar backup</button>
      </div>
    </form>
  </Dialog>
</template>

<style scoped>
.scopes { border: 0; margin: 0 0 10px; padding: 0; display: grid; gap: 8px; }
.scope { display: flex; gap: 8px; align-items: flex-start; padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: 8px; cursor: pointer; font-size: 13px; }
.scope:has(input:checked) { border-color: var(--brand); background: var(--brand-softer); }
.scope small { display: block; color: var(--muted); font-size: 11px; margin-top: 2px; }
.check { display: flex; gap: 8px; align-items: center; font-size: 12px; margin-bottom: 8px; color: #43586a; }
</style>
