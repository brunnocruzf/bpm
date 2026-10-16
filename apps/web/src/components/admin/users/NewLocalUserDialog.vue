<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import RadioButton from 'primevue/radiobutton'
import { api, ApiError } from '@/api/http'
import { useNotify } from '@/composables/notify'
import { USER_CATEGORY_OPTIONS } from '@/composables/format'

const props = defineProps<{ visible: boolean }>()
const emit = defineEmits<{ 'update:visible': [boolean]; created: [] }>()
const notify = useNotify()

const empty = () => ({ name: '', email: '', username: '', area: '', department: '', userCategory: '' })
const form = ref(empty())
const saving = ref(false)
const errors = ref<Record<string, string>>({})

watch(() => props.visible, (v) => { if (v) { form.value = empty(); errors.value = {} } })

async function submit() {
  errors.value = {}
  if (!form.value.userCategory) { errors.value = { userCategory: 'Selecione o tipo de usuário.' }; return }
  saving.value = true
  try {
    const res = await api<{ message: string }>('/admin/managed-users', { method: 'POST', body: form.value })
    notify.success(res.message || 'Conta criada pendente de ativação.')
    emit('update:visible', false)
    emit('created')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))
    notify.error(e)
  } finally { saving.value = false }
}
</script>

<template>
  <Dialog :visible="visible" modal header="Novo usuário local" :style="{ width: 'min(520px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form class="nu-form" @submit.prevent="submit">
      <p class="tm-flow-sub">A conta começa pendente e só ganha acesso depois de ativada pelo link de uso único enviado ao e-mail.</p>
      <div class="tm-field"><label for="new-user-name">Nome completo</label><input id="new-user-name" v-model="form.name" required maxlength="200" /><div v-if="errors.name" class="tm-hint err">{{ errors.name }}</div></div>
      <div class="tm-field"><label for="new-user-email">E-mail</label><input id="new-user-email" v-model="form.email" required type="email" maxlength="200" /><div v-if="errors.email" class="tm-hint err">{{ errors.email }}</div></div>
      <div class="tm-field"><label for="new-user-username">Usuário</label><input id="new-user-username" v-model="form.username" required maxlength="120" autocomplete="off" /><div v-if="errors.username" class="tm-hint err">{{ errors.username }}</div></div>
      <div class="tm-field"><label for="new-user-area">Área</label><input id="new-user-area" v-model="form.area" maxlength="200" /></div>
      <div class="tm-field"><label for="new-user-department">Departamento</label><input id="new-user-department" v-model="form.department" maxlength="200" /></div>
      <fieldset class="tm-radio-group" :aria-invalid="!!errors.userCategory" aria-describedby="new-user-category-error">
        <legend>Tipo de usuário <span class="tm-req" aria-hidden="true">*</span></legend>
        <label v-for="opt in USER_CATEGORY_OPTIONS" :key="opt.value" class="tm-radio-option" :for="`new-user-category-${opt.value}`">
          <RadioButton v-model="form.userCategory" name="new-user-category" :input-id="`new-user-category-${opt.value}`" :value="opt.value" />
          <span><strong>{{ opt.label }}</strong><small>{{ opt.hint }}</small></span>
        </label>
        <div id="new-user-category-error" class="tm-hint err" role="alert">{{ errors.userCategory }}</div>
      </fieldset>
      <div class="tm-actions" style="justify-content:flex-end"><button type="button" class="tm-btn" @click="emit('update:visible', false)">Cancelar</button><button class="tm-btn primary" type="submit" :disabled="saving">{{ saving ? 'Criando…' : 'Criar conta pendente' }}</button></div>
    </form>
  </Dialog>
</template>

<style scoped>
.tm-radio-group { margin: 0 0 12px; padding: 0; border: 0; min-width: 0; display: grid; gap: 8px; }
.tm-radio-group legend { padding: 0; margin-bottom: 6px; color: #53677b; font-size: 12px; font-weight: 600; }
.tm-radio-option { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border: 1px solid var(--line, #d5dde6); border-radius: 8px; cursor: pointer; font-size: 13px; }
.tm-radio-option:has(input:checked) { border-color: var(--brand, #086b77); background: var(--brand-softer, #f3f9fa); }
.tm-radio-option span { display: grid; gap: 1px; }
.tm-radio-option small { color: #738397; font-size: 11px; }
.err { color: var(--red); }
</style>
