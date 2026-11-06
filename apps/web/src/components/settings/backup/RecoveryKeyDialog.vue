<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError, saveBlob } from '@/api/http'
import { useNotify } from '@/composables/notify'

/** A chave só existe nesta tela: o servidor guarda a parte pública, e só depois que ela é colada de volta. */
const props = defineProps<{ visible: boolean; organization: string; destination: string | null }>()
const emit = defineEmits<{ 'update:visible': [boolean]; done: [] }>()
const notify = useNotify()

const privateKey = ref('')
const fingerprint = ref('')
const typed = ref('')
const error = ref('')
const saving = ref(false)
const canConfirm = computed(() => !!typed.value.trim() && !saving.value)

watch(() => props.visible, async (open) => {
  privateKey.value = ''
  typed.value = ''
  error.value = ''
  if (!open) return
  try {
    const res = await api<{ privateKey: string; fingerprint: string }>('/admin/backups/recovery-key', { method: 'POST', body: {} })
    privateKey.value = res.privateKey
    fingerprint.value = res.fingerprint
  } catch (e) {
    notify.error(e)
    emit('update:visible', false)
  }
}, { immediate: true })

async function copy() {
  await navigator.clipboard?.writeText(privateKey.value)
  notify.success('Chave copiada. Guarde no cofre de senhas da empresa.')
}

function downloadKit() {
  const text = [
    `Kit de recuperação do BPM: ${props.organization}`,
    `Gerado em ${new Date().toLocaleString('pt-BR')}. Guarde fora do servidor, no cofre de senhas da empresa.`,
    '',
    'Chave de recuperação:',
    privateKey.value,
    '',
    `Identificação da chave: ${fingerprint.value}`,
    `Destino dos backups: ${props.destination ?? 'somente o servidor (ative o Azure para recuperar em outra máquina)'}`,
    '',
    'Para restaurar em um servidor novo, baixe dr-restore.sh e LEIA-ME-RECUPERACAO.md da pasta de backups no Azure e siga os passos.',
    'Sem esta chave, nenhum backup criado com ela pode ser aberto.',
    '',
  ].join('\n')
  saveBlob(new Blob([text], { type: 'text/plain' }), `chave-recuperacao-${props.organization.toLowerCase()}.txt`)
}

async function confirm() {
  saving.value = true
  error.value = ''
  try {
    await api('/admin/backups/recovery-key/confirm', { method: 'POST', body: { recoveryKey: typed.value.trim() } })
    notify.success('Chave de recuperação confirmada.')
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
  <Dialog :visible="visible" modal header="Chave de recuperação" :style="{ width: 'min(520px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form @submit.prevent="confirm">
      <div class="tm-note err" style="margin-top:0"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span><strong>Esta é a única vez que a chave aparece.</strong> Se ela for perdida, nenhum backup criado com ela poderá ser restaurado, e ninguém consegue recuperá-la.</span></div>
      <div class="tm-field" style="margin-top:14px">
        <label for="k-key">Chave</label>
        <textarea id="k-key" :value="privateKey" readonly class="key" />
        <div class="tm-actions" style="margin-top:8px">
          <button class="tm-btn small" type="button" :disabled="!privateKey" @click="copy"><i class="pi pi-copy" aria-hidden="true" /> Copiar</button>
          <button class="tm-btn small" type="button" :disabled="!privateKey" @click="downloadKit"><i class="pi pi-download" aria-hidden="true" /> Baixar kit de recuperação</button>
        </div>
        <div class="tm-hint">Guarde no cofre de senhas da empresa, fora deste servidor.</div>
      </div>
      <div class="tm-field">
        <label for="k-confirm">Cole a chave para confirmar que ela foi guardada <span class="tm-req">*</span></label>
        <input id="k-confirm" v-model="typed" type="password" autocomplete="off" placeholder="AGE-SECRET-KEY-1..." />
        <div v-if="error" class="tm-hint err">{{ error }}</div>
        <div class="tm-hint">O sistema guarda só a parte pública. Backups antigos continuam pedindo a chave com que foram criados.</div>
      </div>
      <div class="tm-actions" style="justify-content:flex-end">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn primary" type="submit" :disabled="!canConfirm">Confirmar chave</button>
      </div>
    </form>
  </Dialog>
</template>

<style scoped>
.key { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.err { color: var(--red); }
</style>
