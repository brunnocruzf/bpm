<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { api, apiBlob, errorMessage } from '@/api/http'
import { checkBrandColors, isHex } from '@/composables/contrast'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import { brandTokens, useBranding } from '@/stores/branding'
import SettingHeader from '@/components/settings/SettingHeader.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('branding')
const brand = useBranding()
const notify = useNotify()
const confirm = useConfirm()

type Slot = 'main' | 'compact' | 'favicon'
const KIND: Record<Slot, string> = { main: 'logo_main', compact: 'logo_compact', favicon: 'favicon' }
const TOKEN_LABEL: Record<string, string> = { primary: 'Primária', secondary: 'Secundária', primaryText: 'Texto/links', accent: 'Destaque' }
const LABEL: Record<Slot, string> = { main: 'Logo principal', compact: 'Logo compacto (quadrado)', favicon: 'Favicon (quadrado)' }

const form = reactive({ appName: '', orgName: '', tagline: '', colors: { primary: '#086b77', secondary: '#075762', accent: '#2b6cb0' }, logos: { main: null as string | null, compact: null as string | null, favicon: null as string | null }, links: { support: '', privacy: '', help: '' } })
const previews = reactive<Record<Slot, string | null>>({ main: null, compact: null, favicon: null })
const uploading = ref<Slot | null>(null)
const uploadError = ref('')

const contrast = computed(() => checkBrandColors(form.colors))
const contrastOk = computed(() => contrast.value.every((r) => r.ok) && Object.values(form.colors).every(isHex))
// pré-visualização isolada: mesmos tokens (inclusive derivados) aplicados só ao painel
const scopeStyle = computed(() => (Object.values(form.colors).every(isHex) ? brandTokens(form.colors) : {}))

async function loadPreview(slot: Slot, id: string | null) {
  if (previews[slot]) URL.revokeObjectURL(previews[slot]!)
  previews[slot] = null
  if (!id) return
  try {
    const { blob } = await apiBlob(`/admin/settings/branding/assets/${id}`)
    previews[slot] = URL.createObjectURL(blob)
  } catch {
    previews[slot] = null
  }
}

function fill() {
  const v = setting.value?.value
  if (!v) return
  form.appName = v.appName
  form.orgName = v.orgName
  form.tagline = v.tagline
  form.colors = { ...v.colors }
  form.logos = { ...v.logos }
  form.links = { support: v.links?.support ?? '', privacy: v.links?.privacy ?? '', help: v.links?.help ?? '' }
  ;(['main', 'compact', 'favicon'] as Slot[]).forEach((s) => loadPreview(s, form.logos[s]))
}
watch(setting, fill)
onMounted(load)
onBeforeUnmount(() => Object.values(previews).forEach((u) => u && URL.revokeObjectURL(u)))

async function upload(slot: Slot, ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploading.value = slot
  uploadError.value = ''
  try {
    const fd = new FormData()
    fd.append('kind', KIND[slot])
    fd.append('file', file)
    const res = await api<{ id: string }>('/admin/settings/branding/assets', { body: fd })
    form.logos[slot] = res.id
    await loadPreview(slot, res.id)
    notify.info('Imagem validada e recodificada. Clique em "Aplicar" para publicar.')
  } catch (e) {
    uploadError.value = errorMessage(e)
  } finally {
    uploading.value = null
  }
}

function removeLogo(slot: Slot) {
  form.logos[slot] = null
  loadPreview(slot, null)
}

async function apply() {
  if (await save({ appName: form.appName, orgName: form.orgName, tagline: form.tagline, colors: form.colors, logos: form.logos, links: form.links }, 'Identidade visual aplicada.')) {
    await brand.load(true)
    emit('changed')
  }
}

function reset() {
  confirm.require({
    header: 'Restaurar tema padrão?',
    message: 'Nome, cores e logos voltam ao padrão. A alteração é auditada.',
    acceptLabel: 'Restaurar',
    rejectLabel: 'Cancelar',
    accept: async () => {
      if (await action('branding/reset', {}, 'Tema padrão restaurado.')) {
        await brand.load(true)
        emit('changed')
      }
    },
  })
}
</script>

<template>
  <SettingHeader :setting="setting" title="Organização e identidade visual" subtitle="Nome exibido, logos e cores de marca. As cores de estado (sucesso, alerta, erro) não mudam, para preservar a acessibilidade." />

  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Dados e cores</div></div>
      <div class="tm-panel-body">
        <div class="two">
          <div class="tm-field"><label for="b-app">Nome da aplicação <span class="tm-req">*</span></label><input id="b-app" v-model="form.appName" maxlength="60" :aria-invalid="!!err('appName')" /><div v-if="err('appName')" class="tm-hint err">{{ err('appName') }}</div></div>
          <div class="tm-field"><label for="b-org">Nome da organização <span class="tm-req">*</span></label><input id="b-org" v-model="form.orgName" maxlength="80" /></div>
        </div>
        <div class="tm-field"><label for="b-tag">Subtítulo</label><input id="b-tag" v-model="form.tagline" maxlength="80" /></div>

        <div class="colors">
          <div v-for="(label, key) in { primary: 'Cor primária', secondary: 'Cor secundária', accent: 'Cor de destaque (foco)' }" :key="key" class="tm-field">
            <label :for="`c-${key}`">{{ label }}</label>
            <div class="color-row">
              <input :id="`c-${key}-picker`" v-model="form.colors[key]" type="color" :aria-label="`${label} (seletor)`" />
              <input :id="`c-${key}`" v-model="form.colors[key]" maxlength="7" pattern="#[0-9a-fA-F]{6}" />
            </div>
            <div v-if="err(`colors.${key}`)" class="tm-hint err">{{ err(`colors.${key}`) }}</div>
          </div>
        </div>

        <table class="contrast" aria-label="Verificação de contraste WCAG">
          <thead><tr><th>Token</th><th>Uso</th><th>Contraste</th><th>Mínimo</th><th>Situação</th></tr></thead>
          <tbody>
            <tr v-for="r in contrast" :key="r.token">
              <td><span class="swatch" :style="{ background: r.token === 'primaryText' ? r.foreground : form.colors[r.token] }" aria-hidden="true" />{{ TOKEN_LABEL[r.token] }}<div v-if="r.foreground && r.token !== 'primaryText'" class="tm-hint">texto {{ r.foreground === '#ffffff' ? 'branco' : 'escuro' }}</div></td>
              <td>{{ r.label }}</td><td>{{ r.ratio.toFixed(2) }}:1</td><td>{{ r.required }}:1</td>
              <td><span class="tm-pill" :class="r.ok ? 'green' : 'red'">{{ r.ok ? 'OK (WCAG AA)' : 'Insuficiente' }}</span></td>
            </tr>
          </tbody>
        </table>
        <div v-if="!contrastOk" class="tm-note warn" role="alert"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Combinação recusada: ajuste as cores até todas atingirem o contraste mínimo. O servidor também valida.</span></div>

        <h2 style="margin:18px 0 8px">Logos e favicon</h2>
        <p class="tm-hint" style="margin-top:0">PNG ou WebP; o favicon também aceita .ico. SVG não é aceito. As imagens são validadas, verificadas e recodificadas no servidor (o .ico é regravado com 16, 32, 48 px e o tamanho original) e servidas pelo domínio da aplicação.</p>
        <div class="logos">
          <div v-for="slot in (['main', 'compact', 'favicon'] as const)" :key="slot" class="logo-box">
            <div class="tm-strong" style="font-size:12px">{{ LABEL[slot] }}</div>
            <div class="logo-preview"><img v-if="previews[slot]" :src="previews[slot]!" :alt="LABEL[slot]" /><span v-else class="tm-muted">Sem imagem</span></div>
            <div class="tm-actions">
              <label class="tm-btn small" :for="`up-${slot}`"><i class="pi pi-upload" aria-hidden="true" /> {{ uploading === slot ? 'Enviando...' : 'Enviar' }}</label>
              <input :id="`up-${slot}`" type="file" :accept="slot === 'favicon' ? 'image/png,image/webp,.ico,image/x-icon,image/vnd.microsoft.icon' : 'image/png,image/webp'" class="tm-sr-only" @change="(e) => upload(slot, e)" />
              <button v-if="form.logos[slot]" class="tm-btn small ghost" @click="removeLogo(slot)">Remover</button>
            </div>
            <div class="tm-hint" v-if="setting?.value?.limits">Até {{ setting.value.limits[KIND[slot]].max_kb }} KB · {{ setting.value.limits[KIND[slot]].min_w }}–{{ setting.value.limits[KIND[slot]].max_w }} px</div>
          </div>
        </div>
        <div v-if="uploadError" class="tm-note err" role="alert"><i class="pi pi-times-circle" aria-hidden="true" /><span>{{ uploadError }}</span></div>

        <h2 style="margin:18px 0 8px">Links da tela de login</h2>
        <p class="tm-hint" style="margin-top:0">Opcionais: só aparecem quando preenchidos. Aceita endereços https:// ou mailto:.</p>
        <div class="colors">
          <div v-for="(label, key) in { support: 'Suporte (“Fale com o suporte”)', privacy: 'Política de privacidade', help: 'Ajuda' }" :key="key" class="tm-field">
            <label :for="`l-${key}`">{{ label }}</label>
            <input :id="`l-${key}`" v-model="form.links[key]" maxlength="300" placeholder="https://… ou mailto:…" :aria-invalid="!!err(`links.${key}`)" />
            <div v-if="err(`links.${key}`)" class="tm-hint err">{{ err(`links.${key}`) }}</div>
          </div>
        </div>

        <div class="tm-actions" style="margin-top:18px">
          <button class="tm-btn primary" :disabled="saving || !contrastOk || !form.appName.trim() || !form.orgName.trim()" @click="apply"><i class="pi pi-check" aria-hidden="true" /> Aplicar identidade visual</button>
          <button class="tm-btn" :disabled="saving" @click="fill">Descartar alterações</button>
          <button class="tm-btn ghost danger" :disabled="saving" @click="reset">Restaurar tema padrão</button>
        </div>
      </div>
    </section>

    <section class="tm-panel tm-theme-scope" :style="scopeStyle" aria-label="Pré-visualização">
      <div class="tm-panel-head"><div><div class="tm-panel-title">Pré-visualização</div><div class="tm-panel-caption">Antes de aplicar — nada muda para os usuários até salvar</div></div></div>
      <div class="tm-panel-body preview">
        <div class="pv-label">Cabeçalho claro</div>
        <div class="pv-header light">
          <img v-if="previews.main" :src="previews.main" alt="" class="pv-logo" />
          <template v-else><div class="tm-brand-mark">{{ form.appName.slice(0, 3).toUpperCase() || 'BPM' }}</div><div class="tm-brand-name">{{ form.appName }}<span class="tm-brand-sub">{{ form.tagline }}</span></div></template>
          <span style="margin-left:auto" class="tm-pill brand">Minhas tarefas 3</span>
        </div>
        <div class="pv-label">Cabeçalho escuro</div>
        <div class="pv-header dark">
          <img v-if="previews.main" :src="previews.main" alt="" class="pv-logo" />
          <template v-else><div class="tm-brand-mark">{{ form.appName.slice(0, 3).toUpperCase() || 'BPM' }}</div><div class="tm-brand-name" style="color:#fff">{{ form.appName }}</div></template>
        </div>
        <div class="pv-label">Tela de login</div>
        <div class="pv-login">
          <div style="display:flex;align-items:center;gap:10px">
            <img v-if="previews.compact || previews.main" :src="(previews.compact || previews.main)!" alt="" class="pv-logo" style="height:30px" />
            <div v-else class="tm-brand-mark">{{ form.appName.slice(0, 3).toUpperCase() || 'BPM' }}</div>
            <div><strong>{{ form.appName }}</strong><div class="tm-muted" style="font-size:11px">{{ form.orgName }}</div></div>
          </div>
          <button class="tm-btn primary" style="margin-top:12px" tabindex="-1">Entrar com Microsoft</button>
          <a href="#" tabindex="-1" style="display:block;margin-top:8px;font-size:12px" @click.prevent>Link de exemplo</a>
          <input class="pv-focus" value="Campo com foco" tabindex="-1" aria-hidden="true" readonly />
          <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap"><span class="tm-pill green">Concluída</span><span class="tm-pill amber">Em execução</span><span class="tm-pill red">Erro</span></div>
          <div class="tm-hint">Cores de estado permanecem fixas.</div>
        </div>
        <div class="pv-label">Favicon</div>
        <div style="display:flex;align-items:center;gap:8px"><img v-if="previews.favicon" :src="previews.favicon" alt="" width="32" height="32" /><span class="tm-muted" style="font-size:12px">{{ previews.favicon ? 'Aba do navegador' : 'Padrão do BPM' }}</span></div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
@media (max-width: 1300px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.colors { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.color-row { display: flex; gap: 6px; align-items: center; }
.color-row input[type='color'] { width: 40px; height: 34px; padding: 2px; border: 1px solid var(--line-strong); border-radius: 7px; background: #fff; }
.contrast { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 6px; }
.contrast th { text-align: left; color: #788696; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; padding: 6px; }
.contrast td { border-top: 1px solid #edf1f4; padding: 6px; }
.swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; border: 1px solid rgba(0, 0, 0, 0.1); }
.logos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.logo-box { border: 1px solid var(--line); border-radius: 9px; padding: 10px; display: flex; flex-direction: column; gap: 6px; }
.logo-preview { height: 70px; display: grid; place-items: center; background: repeating-conic-gradient(#f2f4f6 0% 25%, #fff 0% 50%) 50% / 14px 14px; border-radius: 6px; }
.logo-preview img { max-height: 60px; max-width: 100%; }
.preview { display: flex; flex-direction: column; gap: 8px; }
.pv-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: #8794a1; font-weight: 700; margin-top: 6px; }
.pv-header { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line); }
.pv-header.light { background: #fff; }
.pv-header.dark { background: #172230; border-color: #172230; }
.pv-logo { height: 30px; max-width: 160px; object-fit: contain; }
.pv-login { padding: 14px; border: 1px solid var(--line); border-radius: 10px; background: #f4f7f9; }
.pv-focus { margin-top: 10px; width: 100%; height: 32px; border-radius: 7px; border: 1px solid var(--line-strong); padding: 0 8px; outline: 2px solid var(--focus-ring); outline-offset: 2px; font-size: 12px; }
.err { color: var(--red); }
</style>
