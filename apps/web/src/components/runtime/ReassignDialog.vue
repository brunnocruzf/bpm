<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import { api, ApiError } from '@/api/http'
import type { TaskSummary } from '@/api/types'
import { useNotify } from '@/composables/notify'

interface UserOption { id: string; name: string; area: string | null; roles: string[] | null; groups: string[] | null; is_active: boolean | null }

/** Reatribuição por administrador de processos. A lista é só uma ajuda: quem decide a elegibilidade é o servidor. */
const props = defineProps<{ visible: boolean; task: Pick<TaskSummary, 'id' | 'candidateGroup' | 'assignee'> }>()
const emit = defineEmits<{ 'update:visible': [boolean]; done: [] }>()
const notify = useNotify()

const users = ref<UserOption[]>([])
const userId = ref('')
const justification = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const options = computed(() => users.value.filter((u) => u.is_active !== false
  && (u.roles ?? []).includes('Bpm.TaskWorker')
  && u.id !== props.task.assignee?.id
  && (!props.task.candidateGroup || (u.groups ?? []).includes(props.task.candidateGroup))))
const canSubmit = computed(() => !!userId.value && justification.value.trim().length >= 10 && !saving.value)

watch(() => props.visible, async (open) => {
  if (!open) return
  userId.value = ''
  justification.value = ''
  errors.value = {}
  try {
    users.value = (await api<{ data: UserOption[] }>('/admin/users')).data
  } catch (e) {
    notify.error(e)
  }
}, { immediate: true })

async function submit() {
  errors.value = {}
  saving.value = true
  try {
    await api(`/tasks/${props.task.id}/reassign`, { method: 'POST', body: { userId: userId.value, justification: justification.value } })
    notify.success('Tarefa reatribuída.')
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
  <Dialog :visible="visible" modal header="Reatribuir tarefa" :style="{ width: 'min(480px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form @submit.prevent="submit">
      <div class="tm-field">
        <label for="re-user">Novo responsável <span class="tm-req">*</span></label>
        <select id="re-user" v-model="userId" required>
          <option value="">Selecione...</option>
          <option v-for="u in options" :key="u.id" :value="u.id">{{ u.name }}{{ u.area ? ` · ${u.area}` : '' }}</option>
        </select>
        <div v-if="errors.userId" class="tm-hint err">{{ errors.userId }}</div>
      </div>
      <div class="tm-field">
        <label for="re-why">Justificativa <span class="tm-req">*</span></label>
        <textarea id="re-why" v-model="justification" rows="3" maxlength="1000" required placeholder="Por que esta tarefa muda de responsável? (mínimo 10 caracteres)" />
        <div v-if="errors.justification" class="tm-hint err">{{ errors.justification }}</div>
      </div>
      <div class="tm-actions" style="justify-content:flex-end">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn primary" type="submit" :disabled="!canSubmit">Reatribuir</button>
      </div>
    </form>
  </Dialog>
</template>
