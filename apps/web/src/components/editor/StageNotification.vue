<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api, errorMessage } from '@/api/http'
import { useNotify } from '@/composables/notify'

/**
 * "Enviar e-mail ao responsável quando esta etapa receber uma nova tarefa" — configurada na etapa
 * de DESTINO. O envio só ocorre depois que o engine confirma a criação da tarefa (outbox).
 */
interface NotifyConfig { enabled: boolean; groupPolicy: 'members' | 'group_mailbox'; onReassign: boolean; subject?: string; body?: string }
interface Preview { enabled: boolean; subject: string; body: string; placeholders: { key: string; label: string }[]; defaults: { subject: string; body: string }; recipients: { name: string; hint: string; kind: string }[] | null; assignment: { mode: string; fallbackGroup: string | null; fallbackGroupName: string | null } | null; canTest: boolean }

const props = defineProps<{ config: NotifyConfig | undefined; editable: boolean; previewBase: string; lane: string | null; assignment?: Record<string, unknown> | null }>()
const emit = defineEmits<{ save: [cfg: NotifyConfig] }>()
const notify = useNotify()

const local = ref<NotifyConfig>({ enabled: false, groupPolicy: 'members', onReassign: true, subject: '', body: '' })
const preview = ref<Preview | null>(null)
const previewError = ref('')
const testTo = ref('')
let timer: number | undefined

watch(() => props.config, (c) => {
  local.value = { enabled: false, groupPolicy: 'members', onReassign: true, subject: '', body: '', ...(c ?? {}) }
}, { immediate: true })

function persist(patch: Partial<NotifyConfig>, debounce = false) {
  local.value = { ...local.value, ...patch }
  window.clearTimeout(timer)
  const run = () => emit('save', { ...local.value, subject: local.value.subject || undefined, body: local.value.body || undefined })
  if (debounce) timer = window.setTimeout(run, 800)
  else run()
}

async function loadPreview() {
  previewError.value = ''
  try {
    preview.value = await api<Preview>(`${props.previewBase}/notification-preview`, { query: { subject: local.value.subject || undefined, body: local.value.body || undefined, groupPolicy: local.value.groupPolicy } })
    if (!local.value.subject && !local.value.body) {
      local.value.subject = preview.value.defaults.subject
      local.value.body = preview.value.defaults.body
    }
  } catch (e) {
    previewError.value = errorMessage(e)
  }
}

async function sendTest() {
  try {
    await api(`${props.previewBase}/notification-test`, { method: 'POST', body: { to: testTo.value } })
    notify.success('E-mail de teste enfileirado com dados de exemplo.')
  } catch (e) {
    notify.error(e)
  }
}
watch(() => local.value.enabled, (on) => { if (on && !preview.value) loadPreview() }, { immediate: true })
watch(() => props.assignment, () => { if (preview.value) loadPreview() })
// chaves duplas montadas em script: o compilador de template trataria "}}" como fim de interpolação
const LINK = '{' + '{link}' + '}'
const tag = (key: string) => '{' + '{' + key + '}' + '}'
const hasLink = computed(() => !local.value.body || local.value.body.includes(LINK))
</script>

<template>
  <div class="notify-box">
    <label class="check">
      <input type="checkbox" :checked="local.enabled" :disabled="!editable" @change="persist({ enabled: ($event.target as HTMLInputElement).checked })" />
      <span><strong>Enviar e-mail ao responsável quando esta etapa receber uma nova tarefa</strong><br /><span class="tm-hint">Desligado por padrão. Em decisões, só a etapa realmente ativada é notificada.</span></span>
    </label>
    <template v-if="local.enabled">
      <div class="two">
        <div class="tm-field"><label for="n-pol">Destinatários</label>
          <select id="n-pol" :value="local.groupPolicy" :disabled="!editable" @change="persist({ groupPolicy: ($event.target as HTMLSelectElement).value as any })">
            <option value="members">Membros ativos do grupo responsável</option>
            <option value="group_mailbox">Caixa de e-mail do grupo (Administração → Grupos)</option>
          </select>
          <div class="tm-hint">{{ assignment ? 'Vale só na contingência: com responsável definido, o e-mail vai apenas para ele.' : 'Tarefa já atribuída: somente o responsável.' }} Nunca endereços digitados em formulários.</div>
        </div>
        <label class="check" style="align-self:center"><input type="checkbox" :checked="local.onReassign" :disabled="!editable" @change="persist({ onReassign: ($event.target as HTMLInputElement).checked })" /> <span>Avisar o novo responsável em reatribuições (sem duplicar)</span></label>
      </div>
      <div class="tm-field"><label for="n-sub">Assunto</label><input id="n-sub" v-model="local.subject" maxlength="200" :disabled="!editable" @input="persist({}, true)" /></div>
      <div class="tm-field"><label for="n-body">Corpo (texto simples)</label><textarea id="n-body" v-model="local.body" rows="6" :disabled="!editable" @input="persist({}, true)" />
        <div v-if="!hasLink" class="tm-hint" style="color:var(--red)">O corpo precisa conter <code v-text="LINK" />.</div>
        <div class="tm-hint">Placeholders permitidos: <code v-for="p in preview?.placeholders ?? []" :key="p.key" :title="p.label" v-text="tag(p.key)" />. Sem código ou expressões; os dados do formulário não vão no e-mail.</div>
      </div>
      <div class="tm-actions"><button class="tm-btn small" type="button" @click="loadPreview"><i class="pi pi-eye" aria-hidden="true" /> Pré-visualizar</button></div>
      <div v-if="previewError" class="tm-note err"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ previewError }}</span></div>
      <div v-if="preview" class="preview">
        <div class="tm-strong" style="font-size:12px">{{ preview.subject }}</div>
        <pre>{{ preview.body }}</pre>
        <div v-if="preview.assignment" class="tm-hint">Responsável definido na execução. Contingência: {{ preview.assignment.fallbackGroupName ?? 'não definida' }}.</div>
        <div v-if="preview.recipients" class="tm-hint">{{ preview.assignment ? 'Destinatários da contingência' : `Destinatários atuais (${lane ?? 'sem grupo'})` }}: <template v-if="preview.recipients.length"><span v-for="r in preview.recipients" :key="r.hint" class="tm-pill neutral" style="margin:1px">{{ r.name }} · {{ r.hint }}</span></template><template v-else>nenhum elegível (a tarefa continua visível na inbox).</template></div>
        <div v-else class="tm-hint">A lista de destinatários é visível apenas para administradores.</div>
        <div v-if="preview.canTest" class="test"><input v-model="testTo" type="email" placeholder="enviar teste para..." aria-label="E-mail para teste" /><button class="tm-btn small" :disabled="!testTo" @click="sendTest">Enviar teste</button></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.notify-box { border: 1px solid var(--line); border-radius: 8px; padding: 10px; margin: 12px 0; background: #fbfcfd; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; color: #43586a; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.preview { margin-top: 8px; border: 1px dashed var(--line-strong); border-radius: 8px; padding: 8px; background: #fff; }
.preview pre { white-space: pre-wrap; font-family: inherit; font-size: 12px; margin: 6px 0; color: #43586a; }
.test { display: flex; gap: 6px; margin-top: 6px; }
.test input { flex: 1; height: 30px; border: 1px solid var(--line-strong); border-radius: 7px; padding: 0 8px; font-size: 12px; }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; margin-right: 3px; }
</style>
