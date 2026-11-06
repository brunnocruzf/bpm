<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import type { BackupEntry } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'

/** Ensaio: carrega o backup em bancos temporários. Prova que o backup restaura e que a chave guardada é a certa. */
const props = defineProps<{ visible: boolean; entry: BackupEntry | null }>()
const emit = defineEmits<{ 'update:visible': [boolean]; done: [] }>()
const notify = useNotify()

const recoveryKey = ref('')
const error = ref('')
const saving = ref(false)

watch(() => props.visible, () => {
  recoveryKey.value = ''
  error.value = ''
})

async function submit() {
  if (!props.entry) return
  saving.value = true
  error.value = ''
  try {
    await api(`/admin/backups/${props.entry.id}/restore-test`, { method: 'POST', body: { recoveryKey: recoveryKey.value.trim() } })
    recoveryKey.value = ''
    notify.success('Teste iniciado. Os dados em uso não são alterados.')
    emit('update:visible', false)
    emit('done')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) error.value = e.errors.recoveryKey?.[0] ?? e.message
    else notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog :visible="visible" modal header="Testar restauração" :style="{ width: 'min(480px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form v-if="entry" @submit.prevent="submit">
      <p class="tm-muted" style="margin:0 0 12px;font-size:12px">O sistema baixa o backup de {{ fmtDateTime(entry.backupAt) }}, abre com a chave e carrega em bancos temporários, que são apagados no fim. Os dados em uso não mudam e o sistema continua no ar.</p>
      <div class="tm-field"><label for="t-key">Chave de recuperação <span class="tm-req">*</span></label>
        <input id="t-key" v-model="recoveryKey" type="password" autocomplete="off" placeholder="AGE-SECRET-KEY-1..." /><div v-if="error" class="tm-hint err">{{ error }}</div></div>
      <div class="tm-actions" style="justify-content:flex-end">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn primary" type="submit" :disabled="!recoveryKey.trim() || saving">Testar</button>
      </div>
    </form>
  </Dialog>
</template>

<style scoped>
.err { color: var(--red); }
</style>
