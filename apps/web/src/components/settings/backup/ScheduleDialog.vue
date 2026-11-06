<script setup lang="ts">
import { reactive, watch } from 'vue'
import Dialog from 'primevue/dialog'
import type { BackupScheduleConfig, BackupScope } from '@/api/types'
import { SCOPE_LABELS, WEEKDAYS } from '@/composables/backup'

const props = defineProps<{ visible: boolean; schedule: BackupScheduleConfig | null; azureAvailable: boolean }>()
const emit = defineEmits<{ 'update:visible': [boolean]; save: [BackupScheduleConfig] }>()

const form = reactive<BackupScheduleConfig>({ frequency: 'daily', time: '02:00', weekday: 0, monthDay: 1, scope: 'database', azure: false, keep: 7, verify: false, enabled: true })
const scopes = Object.keys(SCOPE_LABELS) as BackupScope[]
const days = Array.from({ length: 28 }, (_, i) => i + 1)

watch(() => props.visible, (open) => {
  if (!open) return
  Object.assign(form, { frequency: 'daily', time: '02:00', weekday: 0, monthDay: 1, scope: 'database', azure: props.azureAvailable, keep: 7, verify: false, enabled: true, id: undefined, createdAt: undefined }, props.schedule ?? {})
  form.weekday ??= 0
  form.monthDay ??= 1
})

function submit() {
  emit('save', { ...form })
  emit('update:visible', false)
}
</script>

<template>
  <Dialog :visible="visible" modal :header="schedule ? 'Alterar agendamento' : 'Novo agendamento'" :style="{ width: 'min(480px, 96vw)' }" @update:visible="emit('update:visible', $event)">
    <form @submit.prevent="submit">
      <div class="two">
        <div class="tm-field"><label for="sc-freq">Frequência</label>
          <select id="sc-freq" v-model="form.frequency"><option value="daily">Diário</option><option value="weekly">Semanal</option><option value="monthly">Mensal</option></select></div>
        <div class="tm-field"><label for="sc-time">Horário</label><input id="sc-time" v-model="form.time" type="time" required /></div>
      </div>
      <div v-if="form.frequency === 'weekly'" class="tm-field"><label for="sc-week">Dia da semana</label>
        <select id="sc-week" v-model.number="form.weekday"><option v-for="(d, i) in WEEKDAYS" :key="d" :value="i">{{ d }}</option></select></div>
      <div v-if="form.frequency === 'monthly'" class="tm-field"><label for="sc-day">Dia do mês</label>
        <select id="sc-day" v-model="form.monthDay"><option v-for="d in days" :key="d" :value="d">Dia {{ d }}</option><option value="last">Último dia do mês</option></select></div>
      <div class="two">
        <div class="tm-field"><label for="sc-scope">O que copiar</label>
          <select id="sc-scope" v-model="form.scope"><option v-for="s in scopes" :key="s" :value="s">{{ SCOPE_LABELS[s] }}</option></select></div>
        <div class="tm-field"><label for="sc-keep">Cópias guardadas</label><input id="sc-keep" v-model.number="form.keep" type="number" min="1" max="365" required /></div>
      </div>
      <label v-if="azureAvailable" class="check"><input v-model="form.azure" type="checkbox" /> <span>Enviar uma cópia ao Azure</span></label>
      <label class="check"><input v-model="form.verify" type="checkbox" /> <span>Testar a carga dos bancos ao criar</span></label>
      <label class="check"><input v-model="form.enabled" type="checkbox" /> <span>Agendamento ativo</span></label>
      <div class="tm-actions" style="justify-content:flex-end;margin-top:12px">
        <button class="tm-btn" type="button" @click="emit('update:visible', false)">Cancelar</button>
        <button class="tm-btn primary" type="submit">Salvar</button>
      </div>
    </form>
  </Dialog>
</template>

<style scoped>
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.check { display: flex; gap: 8px; align-items: center; font-size: 12px; margin-bottom: 8px; color: #43586a; }
</style>
