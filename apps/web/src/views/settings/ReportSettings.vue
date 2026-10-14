<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('reports')
const notify = useNotify()
const form = reactive({ renderer: 'native', confidentialityLabel: '', carbone: { licenseApproved: false, approvalReference: '', edition: 'community' } })

interface Template { id: string; key: string; version: number; name: string; kind: string; engine: string; status: string; options: Record<string, any>; fileSha256: string | null; createdBy: string | null; createdAt: string; activatedAt: string | null }
const templates = ref<Template[]>([])
const tpl = reactive({ key: '', name: '', kind: 'instance', engine: 'native', title: '', headerText: '', confidential: true })
const file = ref<File | null>(null)

watch(setting, () => {
  const v = setting.value?.value
  if (v) Object.assign(form, { renderer: v.renderer, confidentialityLabel: v.confidentialityLabel, carbone: { ...v.carbone } })
})
async function loadTemplates() {
  templates.value = (await api<{ data: Template[] }>('/admin/report-templates')).data
}
onMounted(async () => {
  await load()
  await loadTemplates()
})

async function submit() {
  if (await save({ ...form })) emit('changed')
}
async function health() {
  if (await action('reports/carbone-health')) emit('changed')
}
async function createTemplate() {
  try {
    const fd = new FormData()
    Object.entries(tpl).forEach(([k, v]) => fd.append(k, String(v)))
    if (file.value) fd.append('file', file.value)
    await api('/admin/report-templates', { body: fd })
    notify.success('Nova versão de modelo criada (rascunho). Ative para passar a usá-la.')
    await loadTemplates()
  } catch (e) {
    notify.error(e)
  }
}
async function activate(t: Template) {
  try {
    await api(`/admin/report-templates/${t.id}/activate`, { method: 'POST' })
    notify.success(`Modelo ${t.key} v${t.version} ativado.`)
    await loadTemplates()
  } catch (e) {
    notify.error(e)
  }
}
</script>

<template>
  <SettingHeader :setting="setting" title="Relatórios e modelos" subtitle="O Laravel monta os dados já autorizados e mascarados; o renderer só transforma o documento. Geração sempre assíncrona, com arquivos privados e expiração." />
  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Renderer</div></div>
      <div class="tm-panel-body">
        <div class="tm-field"><label for="rp-r">Motor de geração</label>
          <select id="rp-r" v-model="form.renderer"><option value="native">Nativo (PDF/CSV/XLSX gerados pela API — sem dependência externa)</option><option value="carbone">Carbone self-hosted (somente com licença aprovada)</option></select>
          <div v-if="err('renderer')" class="tm-hint err">{{ err('renderer') }}</div>
        </div>
        <div class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>A Community Edition do Carbone usa o "CCL Agreement" (não é licença permissiva) e recursos Enterprise exigem licença. Confirme com jurídico/segurança o uso interno e a forma de hospedagem antes de habilitar; a chave de licença nunca vai para imagem ou Git.</span></div>
        <label class="check"><input v-model="form.carbone.licenseApproved" type="checkbox" /> <span>Licença do Carbone aprovada para o uso pretendido</span></label>
        <div class="two">
          <div class="tm-field"><label for="rp-a">Referência da aprovação</label><input id="rp-a" v-model="form.carbone.approvalReference" /></div>
          <div class="tm-field"><label for="rp-e">Edição</label><select id="rp-e" v-model="form.carbone.edition"><option value="community">Community</option><option value="enterprise">Enterprise (licenciada)</option></select></div>
        </div>
        <div class="tm-field"><label for="rp-c">Selo de confidencialidade (cabeçalho)</label><input id="rp-c" v-model="form.confidentialityLabel" maxlength="80" /></div>
        <div class="tm-actions"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar</button><button class="tm-btn" :disabled="saving" @click="health">Testar serviço Carbone</button></div>
      </div>
    </section>
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Nova versão de modelo</div></div>
      <div class="tm-panel-body">
        <div class="two">
          <div class="tm-field"><label for="t-k">Chave</label><input id="t-k" v-model="tpl.key" placeholder="instancia_qualidade" /></div>
          <div class="tm-field"><label for="t-n">Nome</label><input id="t-n" v-model="tpl.name" /></div>
          <div class="tm-field"><label for="t-kd">Tipo</label><select id="t-kd" v-model="tpl.kind"><option value="instance">Relatório da solicitação</option><option value="operational">Operacional</option></select></div>
          <div class="tm-field"><label for="t-en">Engine</label><select id="t-en" v-model="tpl.engine"><option value="native">Nativo</option><option value="carbone">Carbone (DOCX/ODT/XLSX/ODS)</option></select></div>
        </div>
        <div class="tm-field"><label for="t-ti">Título</label><input id="t-ti" v-model="tpl.title" /></div>
        <div class="tm-field"><label for="t-h">Texto de cabeçalho</label><textarea id="t-h" v-model="tpl.headerText" /></div>
        <label class="check"><input v-model="tpl.confidential" type="checkbox" /> <span>Exibir selo de confidencialidade</span></label>
        <div v-if="tpl.engine === 'carbone'" class="tm-field"><label for="t-f">Arquivo do modelo (sem macros, sem vínculos externos, até 5 MB)</label><input id="t-f" type="file" accept=".docx,.odt,.xlsx,.ods" @change="(e) => (file = (e.target as HTMLInputElement).files?.[0] ?? null)" /></div>
        <button class="tm-btn" :disabled="!tpl.key" @click="createTemplate">Criar versão</button>
      </div>
    </section>
  </div>
  <section class="tm-panel" style="margin-top:16px">
    <div class="tm-panel-head"><div class="tm-panel-title">Modelos versionados</div></div>
    <div style="overflow-x:auto"><table class="tm-table">
      <thead><tr><th>Modelo</th><th>Versão</th><th>Tipo</th><th>Engine</th><th>Estado</th><th>Criado</th><th /></tr></thead>
      <tbody>
        <tr v-for="t in templates" :key="t.id">
          <td><span class="tm-strong">{{ t.name }}</span><div class="tm-hint"><code>{{ t.key }}</code><template v-if="t.fileSha256"> · sha256 {{ t.fileSha256 }}</template></div></td>
          <td>v{{ t.version }}</td><td>{{ t.kind === 'instance' ? 'Solicitação' : 'Operacional' }}</td><td>{{ t.engine }}</td>
          <td><span class="tm-pill" :class="t.status === 'active' ? 'green' : t.status === 'draft' ? 'amber' : 'neutral'">{{ t.status === 'active' ? 'Ativo' : t.status === 'draft' ? 'Rascunho' : 'Substituído' }}</span></td>
          <td>{{ fmtDateTime(t.createdAt) }}<div class="tm-hint">{{ t.createdBy }}</div></td>
          <td><button v-if="t.status !== 'active'" class="tm-link-btn" @click="activate(t)">Ativar</button></td>
        </tr>
      </tbody>
    </table></div>
  </section>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 1200px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin: 8px 0; color: #43586a; }
.err { color: var(--red); }
code { font-size: 10px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
