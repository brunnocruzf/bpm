<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import type { BackupEntry } from '@/api/types'
import { SCOPE_LABELS } from '@/composables/backup'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'

/** Ação destrutiva: pede o nome da organização, a justificativa e a chave de recuperação, que nunca é gravada. */
const props = defineProps<{ visible: boolean; entry: BackupEntry | null; organization: string }>()
const emit = defineEmits<{ 'update:visible': [boolean]; done: [] }>()
const notify = useNotify()

const confirmation = ref('')
const justification = ref('')
const recoveryKey = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const hasFiles = computed(() => props.entry?.scope !== 'database')
const matches = computed(() => confirmation.value.trim().toLocaleUpperCase() === props.organization.toLocaleUpperCase())
const canSubmit = computed(() => matches.value && justification.value.trim().length >= 10 && !!recoveryKey.value.trim() && !saving.value)

watch(() => props.visible, () => {
  confirmation.value = ''
  justification.value = ''
  recoveryKey.value = ''
  errors.value = {}
})

async function submit() {
  if (!props.entry) return
  saving.value = true
  errors.value = {}
  try {
    await api(`/admin/backups/${props.entry.id}/restore`, { method: 'POST', body: { confirmation: confirmation.value, justification: justification.value, recoveryKey: recoveryKey.value.trim() } })
    recoveryKey.value = ''
    notify.success('Restauração iniciada. O sistema segue no ar até a troca dos dados.')
    emit('update:visible', false)
    emit('done')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))
    else notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog :visible="visible" modal header="Restaurar backup" :style="{ width: 'min(520px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form v-if="entry" @submit.prevent="submit">
      <dl class="tm-kv">
        <dt>Backup</dt><dd>{{ fmtDateTime(entry.backupAt) }}, {{ SCOPE_LABELS[entry.scope] }}</dd>
        <dt>Restaura</dt><dd>{{ hasFiles ? 'Bancos e arquivos da plataforma' : 'Bancos' }}</dd>
        <dt>Criado com</dt><dd>Chave <code>{{ entry.keyFingerprint }}</code></dd>
      </dl>
      <div class="tm-note err"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><div><strong>Tudo o que foi registrado depois de {{ fmtDateTime(entry.backupAt) }} será substituído.</strong>
        <ul class="impact">
          <li>O sistema fica indisponível durante a troca dos dados.</li>
          <li>Até você liberar, só administradores entram, e e-mails e integrações ficam pausados.</li>
          <li>Sessões abertas com usuário e senha são encerradas.</li>
        </ul>
      </div></div>
      <div class="tm-field" style="margin-top:14px"><label for="r-org">Digite {{ organization }} para confirmar <span class="tm-req">*</span></label>
        <input id="r-org" v-model="confirmation" autocomplete="off" /><div v-if="errors.confirmation" class="tm-hint err">{{ errors.confirmation }}</div></div>
      <div class="tm-field"><label for="r-why">Justificativa <span class="tm-req">*</span></label>
        <textarea id="r-why" v-model="justification" maxlength="500" placeholder="Por que este backup precisa ser restaurado? (mínimo 10 caracteres)" /><div v-if="errors.justification" class="tm-hint err">{{ errors.justification }}</div></div>
      <div class="tm-field"><label for="r-key">Chave de recuperação <span class="tm-req">*</span></label>
        <input id="r-key" v-model="recoveryKey" type="password" autocomplete="off" placeholder="AGE-SECRET-KEY-1..." /><div v-if="errors.recoveryKey" class="tm-hint err">{{ errors.recoveryKey }}</div><div class="tm-hint">Usada só nesta restauração e nunca gravada.</div></div>
      <div class="tm-note" style="margin-top:0"><i class="pi pi-info-circle" aria-hidden="true" /><span>Antes de começar, o sistema faz uma cópia do estado atual. Até você liberar o sistema, é possível desfazer a restauração.</span></div>
      <div class="tm-actions" style="justify-content:flex-end;margin-top:14px">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn danger-solid" type="submit" :disabled="!canSubmit">Restaurar</button>
      </div>
    </form>
  </Dialog>
</template>

<style scoped>
.impact { margin: 6px 0 0; padding-left: 16px; display: grid; gap: 3px; }
.err { color: var(--red); }
.tm-note.err { color: #87413a; }
code { font-size: 11px; background: rgba(0, 0, 0, 0.05); padding: 1px 4px; border-radius: 4px; }
.danger-solid { color: #fff; background: var(--red); border-color: var(--red); }
.danger-solid:hover:not(:disabled) { color: #fff; background: #9c3a3a; border-color: #9c3a3a; }
</style>
