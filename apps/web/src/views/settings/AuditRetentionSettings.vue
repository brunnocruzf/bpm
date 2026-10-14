<script setup lang="ts">
import { onMounted, reactive, watch } from 'vue'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save } = useSetting<any>('audit-retention')
const form = reactive({ retentionDays: '' as string | number, approvalReference: '', approvedBy: '', notificationRetentionDays: '' as string | number, syncRunRetentionDays: '' as string | number, reportArtifactHours: 24 })

watch(setting, () => {
  const v = setting.value?.value
  if (!v) return
  Object.assign(form, { retentionDays: v.retentionDays ?? '', approvalReference: v.approvalReference, approvedBy: v.approvedBy, notificationRetentionDays: v.notificationRetentionDays ?? '', syncRunRetentionDays: v.syncRunRetentionDays ?? '', reportArtifactHours: v.reportArtifactHours })
})
onMounted(load)

const num = (v: string | number) => (v === '' || v === null ? null : Number(v))
async function submit() {
  if (await save({ retentionDays: num(form.retentionDays), approvalReference: form.approvalReference, approvedBy: form.approvedBy, notificationRetentionDays: num(form.notificationRetentionDays), syncRunRetentionDays: num(form.syncRunRetentionDays), reportArtifactHours: Number(form.reportArtifactHours) })) emit('changed')
}
</script>

<template>
  <SettingHeader :setting="setting" title="Auditoria e retenção" subtitle="A trilha de auditoria é append-only (aplicação e banco). O produto não define prazo legal: sem prazo aprovado nada é expurgado." />
  <section class="tm-panel">
    <div class="tm-panel-body">
      <div class="tm-note warn" style="margin-top:0"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Defina o prazo somente com aprovação de privacidade/jurídico/segurança. O expurgo diário arquiva antes (JSONL + manifesto SHA-256 no storage) e registra o próprio evento. Backup e arquivamento imutável (WORM) são responsabilidade da infraestrutura — ver runbook.</span></div>
      <div class="three">
        <div class="tm-field"><label for="r-d">Retenção da auditoria (dias)</label><input id="r-d" v-model="form.retentionDays" type="number" min="30" placeholder="Indefinida" /><div v-if="err('retentionDays')" class="tm-hint err">{{ err('retentionDays') }}</div></div>
        <div class="tm-field"><label for="r-a">Referência da aprovação</label><input id="r-a" v-model="form.approvalReference" placeholder="ex.: JUR-2026-001" /><div v-if="err('approvalReference')" class="tm-hint err">{{ err('approvalReference') }}</div></div>
        <div class="tm-field"><label for="r-b">Aprovado por</label><input id="r-b" v-model="form.approvedBy" /></div>
      </div>
      <div class="three">
        <div class="tm-field"><label for="r-n">Outbox de notificações (dias)</label><input id="r-n" v-model="form.notificationRetentionDays" type="number" min="7" placeholder="Indefinida" /></div>
        <div class="tm-field"><label for="r-s">Execuções de sincronização (dias)</label><input id="r-s" v-model="form.syncRunRetentionDays" type="number" min="7" placeholder="Indefinida" /></div>
        <div class="tm-field"><label for="r-h">Arquivos de relatório (horas)</label><input id="r-h" v-model="form.reportArtifactHours" type="number" min="1" max="720" /></div>
      </div>
      <p class="tm-hint">Encadeamento criptográfico (hash-chain) não foi adotado: exige rotação/verificação próprias e não substitui controle de acesso, backup ou armazenamento imutável (ver ADR-0010).</p>
      <div class="tm-actions"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar</button><RouterLink class="tm-btn" :to="{ name: 'audit' }">Abrir trilha de auditoria</RouterLink></div>
    </div>
  </section>
</template>

<style scoped>
.three { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px; }
.err { color: var(--red); }
</style>
