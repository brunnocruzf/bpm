<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import { useSession } from '@/stores/session'
import { useNotify } from '@/composables/notify'

const visible = defineModel<boolean>('visible', { required: true })
const session = useSession()
const notify = useNotify()

const name = ref('')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

watch(visible, (v) => { if (v) { name.value = ''; errors.value = {} } })

async function create() {
  saving.value = true
  errors.value = {}
  try {
    const current = session.meta?.categories.filter((c) => c !== 'Outros') ?? []
    await api('/admin/settings/categories', { method: 'PUT', body: { items: [...current, name.value.trim()] } })
    await session.refreshMeta()
    visible.value = false
    notify.success('Área criada.')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else notify.error(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog v-model:visible="visible" modal header="Nova área" :style="{ width: 'min(420px, 96vw)' }">
    <form @submit.prevent="create">
      <div class="tm-field">
        <label for="nc-name">Nome da área <span class="tm-req">*</span></label>
        <input id="nc-name" v-model="name" required maxlength="40" placeholder="Ex.: Logística" autofocus />
        <div v-if="errors.items" class="tm-hint" style="color:var(--red)">{{ errors.items[0] }}</div>
      </div>
      <div class="tm-actions" style="justify-content:flex-end;margin-top:18px">
        <button type="button" class="tm-btn" @click="visible = false">Cancelar</button>
        <button type="submit" class="tm-btn primary" :disabled="saving || !name.trim()">Criar</button>
      </div>
    </form>
  </Dialog>
</template>
