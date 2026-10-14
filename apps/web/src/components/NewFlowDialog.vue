<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'

const visible = defineModel<boolean>('visible', { required: true })
const router = useRouter()
const session = useSession()
const notify = useNotify()

const name = ref('')
const category = ref('Qualidade')
const description = ref('')
const key = ref('')
const keyTouched = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const suggestedKey = computed(() =>
  name.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 48),
)
watch(suggestedKey, (v) => { if (!keyTouched.value) key.value = v })
watch(visible, (v) => { if (v) { name.value = ''; description.value = ''; key.value = ''; keyTouched.value = false; errors.value = {} } })

async function create() {
  saving.value = true
  errors.value = {}
  try {
    const res = await api<{ flow: { key: string } }>('/flows', {
      method: 'POST',
      body: { name: name.value.trim(), category: category.value, description: description.value.trim() || null, key: keyTouched.value ? key.value : null },
    })
    visible.value = false
    notify.success('Fluxo criado. Etapa 1 e Formulário da Etapa 1 já estão associados.')
    router.push({ name: 'flow-editor', params: { flowKey: res.flow.key } })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog v-model:visible="visible" modal header="Criar novo fluxo BPMN" :style="{ width: 'min(520px, 96vw)' }">
    <p class="tm-muted" style="margin:0 0 16px;font-size:12px">Defina o processo. O sistema criará a primeira etapa e o formulário já associados para você começar a configurar.</p>
    <form @submit.prevent="create">
      <div class="tm-field">
        <label for="nf-name">Nome do fluxo <span class="tm-req">*</span></label>
        <input id="nf-name" v-model="name" required maxlength="90" placeholder="Ex.: Solicitação de compra" autofocus />
        <div v-if="errors.name" class="tm-hint" style="color:var(--red)">{{ errors.name[0] }}</div>
      </div>
      <div class="tm-field">
        <label for="nf-cat">Área / categoria</label>
        <select id="nf-cat" v-model="category">
          <option v-for="c in session.meta?.categories" :key="c">{{ c }}</option>
        </select>
      </div>
      <div class="tm-field">
        <label for="nf-desc">Descrição</label>
        <textarea id="nf-desc" v-model="description" maxlength="240" placeholder="Para que serve este processo? (opcional)" />
      </div>
      <div class="tm-field">
        <label for="nf-key">Chave técnica</label>
        <input id="nf-key" v-model="key" maxlength="64" pattern="[a-z][a-z0-9_]{2,63}" @input="keyTouched = true" />
        <div class="tm-hint">Identificador do processo no BPMN. Sugerida a partir do nome; não muda depois de publicada.</div>
        <div v-if="errors.key" class="tm-hint" style="color:var(--red)">{{ errors.key[0] }}</div>
      </div>
      <div class="tm-note" style="margin-top:4px"><i class="pi pi-check" /><span>Ao criar, o rascunho já terá <b>Etapa 1</b> + <b>Formulário da Etapa 1</b> vinculados e um caminho inicial Início → Etapa 1 → Fim.</span></div>
      <div class="tm-actions" style="justify-content:flex-end;margin-top:18px">
        <button type="button" class="tm-btn" @click="visible = false">Cancelar</button>
        <button type="submit" class="tm-btn primary" :disabled="saving || name.trim().length < 3"><i class="pi pi-plus" /> Criar fluxo</button>
      </div>
    </form>
  </Dialog>
</template>
