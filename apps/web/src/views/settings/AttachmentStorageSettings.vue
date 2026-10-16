<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/http'
import { fmtDateTime, fmtSize } from '@/composables/format'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'
import IntegrationLog from '@/components/settings/IntegrationLog.vue'

interface Usage { disk: string; files: number; bytes: number; label: string; status: string }

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('attachments-storage')
const logKey = ref(0)
const usage = ref<Usage[]>([])

const form = reactive({
  provider: 'platform' as 'platform' | 'azure_blob',
  azure: { endpointMode: 'public', cloud: 'global', accountName: '', container: '', pathPrefix: '', approvalReference: '', checklist: { privateContainer: false, softDelete: false, dedicatedAccount: false, hostDeclared: false } as Record<string, boolean> },
})
// a chave é somente escrita: o campo nunca vem preenchido do servidor
const secret = reactive({ accountKey: '', credentialFileRef: '' })

const CHECKLIST: { key: string; label: string }[] = [
  { key: 'dedicatedAccount', label: 'Conta de armazenamento dedicada ao BPM (a chave dá acesso à conta inteira).' },
  { key: 'privateContainer', label: 'Contêiner criado com acesso público desativado (privado).' },
  { key: 'softDelete', label: 'Exclusão reversível (soft delete) e/ou versionamento habilitados, conforme a política de retenção.' },
  { key: 'hostDeclared', label: 'Host <conta>.blob.core.windows.net declarado pela plataforma em BPM_INTEGRATION_ALLOWED_HOSTS e firewall da conta liberado para o servidor.' },
]

const v = computed(() => setting.value?.value)
const isAzure = computed(() => form.provider === 'azure_blob')
const isEmulator = computed(() => form.azure.endpointMode === 'emulator')
const effective = computed(() => v.value?.effective)
const keyConfigured = computed(() => !!v.value?.azure?.credential?.configured)
const canTest = computed(() => !!setting.value && setting.value.status !== 'incomplete' && setting.value.status !== 'disabled')
const canActivate = computed(() => setting.value?.status === 'configured' && setting.value?.lastValidation?.code === 'storage_ok' && setting.value?.lastValidation?.version === setting.value?.version && !setting.value?.activatedAt)

function fill() {
  const s = setting.value?.value
  if (!s) return
  form.provider = s.provider
  Object.assign(form.azure, {
    endpointMode: s.azure.endpointMode, cloud: s.azure.cloud, accountName: s.azure.accountName, container: s.azure.container, pathPrefix: s.azure.pathPrefix,
    approvalReference: s.azure.approvalReference, checklist: { ...form.azure.checklist, ...(s.azure.checklist ?? {}) },
  })
}
watch(setting, fill)

async function loadUsage() {
  try { usage.value = (await api<{ data: Usage[] }>('/admin/settings/attachments-storage/usage')).data } catch { usage.value = [] }
}
onMounted(async () => { await load(); fill(); await loadUsage() })

async function submit() {
  const azure: Record<string, unknown> = { ...form.azure }
  if (secret.accountKey.trim()) azure.accountKey = secret.accountKey.trim()
  if (secret.credentialFileRef.trim()) azure.credentialFileRef = secret.credentialFileRef.trim()
  if (await save({ provider: form.provider, azure })) {
    secret.accountKey = ''
    secret.credentialFileRef = ''
    emit('changed')
  }
  logKey.value++
}
async function test() {
  if (await action('attachments-storage/test')) emit('changed')
  logKey.value++
}
async function activate() {
  if (!window.confirm('Os NOVOS anexos passarão a ser gravados no Azure Blob Storage. Os anexos já gravados continuam onde estão. Ativar?')) return
  if (await action('attachments-storage/activate', {}, 'Destino ativado. Novos anexos usam o Azure Blob Storage.')) { emit('changed'); await loadUsage() }
  logKey.value++
}
async function deactivate() {
  if (!window.confirm('Voltar ao armazenamento padrão da plataforma para os novos anexos? Os anexos no Azure continuam legíveis.')) return
  if (await action('attachments-storage/deactivate', {}, 'Os novos anexos voltaram ao armazenamento padrão.')) { emit('changed'); await loadUsage() }
  logKey.value++
}
</script>

<template>
  <SettingHeader :setting="setting" title="Armazenamento de anexos" subtitle="Onde os arquivos enviados nos formulários (Form.io) e nas ações dos fornecedores são gravados. O navegador nunca fala com o Azure: uploads e downloads passam pela API, que gera links assinados de 5 minutos." />

  <div class="tm-note" style="margin-top:0"><i class="pi pi-info-circle" aria-hidden="true" /><span>
    Em vigor para novos anexos:
    <strong v-if="effective?.provider === 'azure_blob'">Azure Blob Storage — {{ effective.accountName }}/{{ effective.container }}<template v-if="effective.pathPrefix"> ({{ effective.pathPrefix }}/)</template><template v-if="effective.emulator"> · emulador Azurite</template></strong>
    <strong v-else>armazenamento da plataforma ({{ v?.environment?.driver || 'padrão do ambiente' }})</strong>.
    Logos, relatórios, auditoria e fotos continuam no armazenamento da plataforma. Mudar o destino <strong>não move</strong> arquivos já gravados.
  </span></div>

  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Destino</div></div>
      <div class="tm-panel-body">
        <fieldset class="radios">
          <legend class="tm-sr-only">Destino dos anexos</legend>
          <label v-for="p in v?.providers ?? []" :key="p.key" class="radio"><input v-model="form.provider" type="radio" name="provider" :value="p.key" /> <span>{{ p.label }}</span></label>
        </fieldset>
        <div v-if="err('provider')" class="tm-hint err">{{ err('provider') }}</div>

        <template v-if="isAzure">
          <h2>Azure Blob Storage — autenticação por chave da conta (Shared Key)</h2>
          <div v-if="v?.emulatorAvailable" class="tm-field"><label for="a-mode">Endpoint</label>
            <select id="a-mode" v-model="form.azure.endpointMode"><option value="public">Conta do Azure (https://&lt;conta&gt;.blob.…)</option><option value="emulator">Emulador Azurite (somente desenvolvimento)</option></select>
            <div v-if="err('azure.endpointMode')" class="tm-hint err">{{ err('azure.endpointMode') }}</div></div>
          <div class="two">
            <div v-if="!isEmulator" class="tm-field"><label for="a-acc">Nome da conta de armazenamento</label><input id="a-acc" v-model="form.azure.accountName" placeholder="ex.: tmsabpmanexos" autocomplete="off" /><div v-if="err('azure.accountName')" class="tm-hint err">{{ err('azure.accountName') }}</div></div>
            <div v-if="!isEmulator" class="tm-field"><label for="a-cloud">Nuvem</label><select id="a-cloud" v-model="form.azure.cloud"><option value="global">Azure global (core.windows.net)</option><option value="usgov">Azure Government</option><option value="china">Azure China</option></select></div>
            <div class="tm-field"><label for="a-cont">Contêiner (privado)</label><input id="a-cont" v-model="form.azure.container" placeholder="ex.: anexos" autocomplete="off" /><div v-if="err('azure.container')" class="tm-hint err">{{ err('azure.container') }}</div></div>
            <div class="tm-field"><label for="a-pre">Prefixo (opcional)</label><input id="a-pre" v-model="form.azure.pathPrefix" placeholder="ex.: bpm/homologacao" autocomplete="off" /><div v-if="err('azure.pathPrefix')" class="tm-hint err">{{ err('azure.pathPrefix') }}</div></div>
          </div>
          <div class="two">
            <div class="tm-field"><label for="a-key">Chave da conta — somente escrita</label>
              <input id="a-key" v-model="secret.accountKey" type="password" autocomplete="new-password" :placeholder="keyConfigured ? '•••••• (definida — preencha para substituir)' : 'Chave 1 ou 2 (base64)'" />
              <div v-if="err('azure.accountKey')" class="tm-hint err">{{ err('azure.accountKey') }}</div>
              <div class="tm-hint">Portal do Azure → Conta de armazenamento → Segurança + rede → Chaves de acesso. Atual: {{ keyConfigured ? `definida (${v?.azure?.credential?.source})` : 'não definida' }}. A chave nunca é exibida depois de salva.</div></div>
            <div class="tm-field"><label for="a-ref">Ou segredo montado (recomendado em produção)</label><input id="a-ref" v-model="secret.credentialFileRef" placeholder="file:azure_storage_key" autocomplete="off" /><div v-if="err('azure.credentialFileRef')" class="tm-hint err">{{ err('azure.credentialFileRef') }}</div></div>
          </div>
          <div class="tm-field"><label for="a-appr">Referência da aprovação do uso de chave de conta</label><input id="a-appr" v-model="form.azure.approvalReference" placeholder="ex.: SEC-2026-042" autocomplete="off" /><div class="tm-hint">A chave dá acesso à conta inteira; use uma conta dedicada ao BPM e rotacione a chave periodicamente (substitua e teste de novo: os anexos antigos continuam legíveis).</div></div>
          <label v-for="c in CHECKLIST" :key="c.key" class="check"><input v-model="form.azure.checklist[c.key]" type="checkbox" /> <span>{{ c.label }}</span></label>
        </template>
        <div class="tm-actions" style="margin-top:12px"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar</button></div>
      </div>
    </section>

    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Testar e ativar</div></div>
      <div class="tm-panel-body steps">
        <p class="tm-hint" style="margin:0">Salvar não muda o destino dos novos anexos. O teste grava, lê e apaga um arquivo de prova de verdade; só depois é possível ativar.</p>
        <div class="step"><strong>1. Testar conexão</strong><span class="tm-muted">Política de rede, credencial, contêiner privado, gravação, leitura (SHA-256) e exclusão.</span>
          <button class="tm-btn small" :disabled="saving || !canTest || !isAzure" @click="test"><i class="pi pi-bolt" aria-hidden="true" /> Testar</button></div>
        <div class="step"><strong>2. Ativar para novos anexos</strong><span class="tm-muted">Somente com o teste da versão atual aprovado. Anexos já gravados não mudam de lugar.</span>
          <div class="tm-actions"><button class="tm-btn small primary" :disabled="!canActivate || saving" @click="activate">Ativar</button>
            <button v-if="effective?.provider === 'azure_blob'" class="tm-btn small ghost" :disabled="saving" @click="deactivate">Voltar ao padrão</button></div>
          <span v-if="setting?.activatedAt" class="tm-hint">Ativada em {{ fmtDateTime(setting.activatedAt) }}</span></div>
      </div>
      <div class="tm-panel-head" style="border-top:1px solid #edf1f4"><div class="tm-panel-title">Onde estão os anexos</div></div>
      <div class="tm-panel-body">
        <table v-if="usage.length" class="usage"><thead><tr><th>Destino</th><th>Arquivos</th><th>Tamanho</th></tr></thead>
          <tbody><tr v-for="u in usage" :key="u.disk"><td>{{ u.label }}<span v-if="u.status === 'retired'" class="tm-pill neutral" style="margin-left:6px">aposentado</span></td><td>{{ u.files }}</td><td>{{ fmtSize(u.bytes) }}</td></tr></tbody></table>
        <p v-else class="tm-hint" style="margin:0">Nenhum anexo gravado ainda.</p>
      </div>
    </section>
  </div>

  <IntegrationLog
    area="storage"
    title="Log do armazenamento de anexos"
    caption="Cada teste (com as etapas e o tempo de cada uma), ativações e alterações da configuração. Clique em um registro para ver o que significa o erro e o que fazer. Sem chaves nem URLs assinadas."
    :kind-labels="{ test: 'Testes', config: 'Configuração' }"
    :refresh-key="logKey"
  />
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; margin: 16px 0; }
@media (max-width: 1300px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 760px) { .two { grid-template-columns: 1fr; } }
h2 { margin: 14px 0 8px; font-size: 14px; }
.radios { border: 0; margin: 0 0 6px; padding: 0; display: grid; gap: 8px; }
.radio { display: flex; gap: 8px; align-items: center; font-size: 13px; padding: 9px 12px; border: 1px solid var(--line, #d5dde6); border-radius: 8px; cursor: pointer; }
.radio:has(input:checked) { border-color: var(--brand); background: var(--brand-softer); }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin-bottom: 8px; color: #43586a; }
.check input { margin-top: 2px; }
.steps { display: flex; flex-direction: column; gap: 12px; }
.step { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
.step .tm-btn { align-self: flex-start; }
.usage { width: 100%; border-collapse: collapse; font-size: 12px; }
.usage th, .usage td { text-align: left; padding: 6px 4px; border-bottom: 1px solid #edf1f4; }
.err { color: var(--red); }
</style>
