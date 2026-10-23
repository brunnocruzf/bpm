<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/http'
import { fmtDateTime } from '@/composables/format'
import { useNotify } from '@/composables/notify'
import { useSetting } from '@/composables/useSetting'
import SettingHeader from '@/components/settings/SettingHeader.vue'
import IntegrationLog from '@/components/settings/IntegrationLog.vue'

const emit = defineEmits<{ changed: [] }>()
const { setting, saving, err, load, save, action } = useSetting<any>('email')
const notify = useNotify()

type EmailTextConfig = { eyebrow: string; headline: string; actionLabel: string; bodyIntro: string }
const DEFAULT_EMAIL_TEXTS: Record<string, EmailTextConfig> = {
  'task.assigned': { eyebrow: 'Ação necessária', headline: 'Uma tarefa aguarda sua atenção', actionLabel: 'Acessar tarefa', bodyIntro: '' },
  'stage.completed': { eyebrow: 'Atualização do processo', headline: 'Etapa concluída', actionLabel: 'Acompanhar solicitação', bodyIntro: '' },
  'instance.ended': { eyebrow: 'Processo finalizado', headline: 'Solicitação encerrada', actionLabel: 'Ver solicitação', bodyIntro: '' },
  'identity.invitation': { eyebrow: 'Acesso ao BPM', headline: 'Você recebeu um convite', actionLabel: 'Ativar minha conta', bodyIntro: '' },
  'identity.password_reset': { eyebrow: 'Segurança da conta', headline: 'Redefina sua senha', actionLabel: 'Criar nova senha', bodyIntro: '' },
  'admin.alert': { eyebrow: 'Operação do sistema', headline: 'Alerta do BPM', actionLabel: 'Abrir o BPM', bodyIntro: '' },
  'email.test': { eyebrow: 'Verificação de entrega', headline: 'E-mail de teste', actionLabel: 'Acessar o BPM', bodyIntro: '' },
}
const form = reactive({
  provider: 'disabled', fromAddress: '', fromName: '', replyTo: '', timeout: 15,
  layout: {
    accentColor: '#f36d32', headerTitle: 'BPM', footerText: 'Mensagem automática do BPM. Não responda a este e-mail.',
    linkHelpText: 'Se o botão não abrir, copie este endereço no navegador:',
    texts: Object.fromEntries(Object.entries(DEFAULT_EMAIL_TEXTS).map(([key, value]) => [key, { ...value }])) as Record<string, EmailTextConfig>,
  },
  smtp: { host: '', port: 587, encryption: 'starttls', auth: true, username: '' },
  m365: { tenantId: '', clientId: '', credentialType: 'certificate', senderMailbox: '', saveToSentItems: false, checklist: { mailSendPermission: false, mailboxRestricted: false, smtpAuthEnabled: false } as Record<string, boolean> },
})
const secret = reactive({ smtpPassword: '', smtpPasswordFileRef: '', m365CertificatePem: '', m365ClientSecret: '', m365FileRef: '' })
const testTo = ref('')
interface TestMsg { id: string; state: string; provider: string | null; providerMessageId: string | null; errorCode: string | null; error: string | null; attempts: number; createdAt: string; sentAt: string | null; durationMs: number | null }
const testMsg = ref<TestMsg | null>(null)
const previewSample = ref('stage.completed')
const previewHtml = ref('')
const previewRenderer = ref('')
const previewLoading = ref(false)
const selectedEmailText = computed(() => form.layout.texts[previewSample.value] ?? DEFAULT_EMAIL_TEXTS[previewSample.value])
// recarrega o log depois de salvar/testar
const logKey = ref(0)
let poll: number | undefined

const providers = computed<{ key: string; label: string; available: boolean }[]>(() => setting.value?.value?.providers ?? [])
const isM365 = computed(() => form.provider === 'm365_graph' || form.provider === 'm365_smtp_oauth')

function fill() {
  const v = setting.value?.value
  if (!v) return
  const savedTexts = v.layout?.texts ?? {}
  const texts = Object.fromEntries(Object.entries(DEFAULT_EMAIL_TEXTS).map(([key, defaults]) => [key, { ...defaults, ...(savedTexts[key] ?? {}) }])) as Record<string, EmailTextConfig>
  Object.assign(form, {
    provider: v.provider, fromAddress: v.fromAddress, fromName: v.fromName, replyTo: v.replyTo, timeout: v.timeout,
    layout: { ...form.layout, ...(v.layout ?? {}), texts },
    smtp: { host: v.smtp.host, port: v.smtp.port, encryption: v.smtp.encryption, auth: v.smtp.auth, username: v.smtp.username },
    m365: { tenantId: v.m365.tenantId, clientId: v.m365.clientId, credentialType: v.m365.credentialType, senderMailbox: v.m365.senderMailbox, saveToSentItems: v.m365.saveToSentItems, checklist: { ...form.m365.checklist, ...(v.m365.checklist ?? {}) } },
  })
}
watch(setting, fill)
onMounted(async () => {
  await load()
  fill()
  await refreshPreview()
})
onBeforeUnmount(() => window.clearTimeout(poll))

watch(() => form.smtp.encryption, (e) => {
  if (e === 'tls' && form.smtp.port === 587) form.smtp.port = 465
  if (e === 'starttls' && form.smtp.port === 465) form.smtp.port = 587
})

async function submit() {
  const body: any = { provider: form.provider, fromAddress: form.fromAddress, fromName: form.fromName, replyTo: form.replyTo, timeout: form.timeout, layout: { ...form.layout }, smtp: { ...form.smtp }, m365: { ...form.m365 } }
  if (secret.smtpPassword) body.smtp.password = secret.smtpPassword
  if (secret.smtpPasswordFileRef) body.smtp.passwordFileRef = secret.smtpPasswordFileRef
  if (secret.m365CertificatePem) body.m365.certificatePem = secret.m365CertificatePem
  if (secret.m365ClientSecret) body.m365.clientSecret = secret.m365ClientSecret
  if (secret.m365FileRef) body.m365.credentialFileRef = secret.m365FileRef
  if (await save(body)) {
    Object.assign(secret, { smtpPassword: '', smtpPasswordFileRef: '', m365CertificatePem: '', m365ClientSecret: '', m365FileRef: '' })
    emit('changed')
  }
  logKey.value++
}

async function refreshPreview() {
  previewLoading.value = true
  try {
    const result = await api<{ html: string; renderer: string }>('/admin/settings/email/preview-template', {
      method: 'POST', body: { sample: previewSample.value, layout: { ...form.layout } },
    })
    previewHtml.value = result.html
    previewRenderer.value = result.renderer
  } catch (e) {
    notify.error(e)
  } finally {
    previewLoading.value = false
  }
}

async function testConnection() {
  if (await action('email/test-connection')) emit('changed')
  logKey.value++
}

async function pollTest(id: string) {
  testMsg.value = await api<TestMsg>(`/admin/settings/email/test/${id}`)
  if (['queued', 'sending', 'retrying'].includes(testMsg.value.state)) {
    poll = window.setTimeout(() => pollTest(id), 1500)
  } else {
    await load()
    emit('changed')
    logKey.value++
  }
}
async function sendTest() {
  try {
    const msg = await api<TestMsg>('/admin/settings/email/test', { method: 'POST', body: { to: testTo.value } })
    notify.info('E-mail de teste enfileirado; o worker envia em instantes.')
    await pollTest(msg.id)
  } catch (e) {
    notify.error(e)
  }
}
const STATE_LABEL: Record<string, string> = { queued: 'Na fila', sending: 'Enviando', sent: 'Aceito pelo provedor', retrying: 'Aguardando nova tentativa', failed: 'Falhou', skipped: 'Descartado' }
</script>

<template>
  <SettingHeader :setting="setting" title="E-mail e notificações" subtitle="O envio é sempre assíncrono (fila + outbox, com retry e dead-letter) e nunca bloqueia a submissão de formulários. Segredos são somente escrita." />

  <div class="grid">
    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Provedor</div></div>
      <div class="tm-panel-body">
        <div class="tm-field"><label for="m-p">Tipo de provedor</label>
          <select id="m-p" v-model="form.provider"><option v-for="p in providers" :key="p.key" :value="p.key" :disabled="!p.available">{{ p.label }}{{ p.available ? '' : ' (indisponível em produção)' }}</option></select>
          <div v-if="err('provider')" class="tm-hint err">{{ err('provider') }}</div>
          <div class="tm-hint">Provedores transacionais (SES, SendGrid, Mailgun, Postmark) entram após aprovação de licença/segurança e instalação do transport oficial.</div>
        </div>
        <div class="three">
          <div class="tm-field"><label for="m-from">Remetente</label><input id="m-from" v-model="form.fromAddress" type="email" /><div v-if="err('fromAddress')" class="tm-hint err">{{ err('fromAddress') }}</div></div>
          <div class="tm-field"><label for="m-name">Nome do remetente</label><input id="m-name" v-model="form.fromName" /></div>
          <div class="tm-field"><label for="m-reply">Reply-to</label><input id="m-reply" v-model="form.replyTo" type="email" /><div v-if="err('replyTo')" class="tm-hint err">{{ err('replyTo') }}</div></div>
        </div>

        <template v-if="form.provider === 'smtp'">
          <h2>SMTP personalizado</h2>
          <div class="three">
            <div class="tm-field"><label for="s-h">Host</label><input id="s-h" v-model="form.smtp.host" placeholder="smtp.empresa.com.br" /><div v-if="err('smtp.host')" class="tm-hint err">{{ err('smtp.host') }}</div></div>
            <div class="tm-field"><label for="s-e">Criptografia</label><select id="s-e" v-model="form.smtp.encryption"><option value="starttls">STARTTLS (587) — exigido</option><option value="tls">TLS implícito / SMTPS (465)</option><option value="none">Sem criptografia (somente relay interno declarado)</option></select></div>
            <div class="tm-field"><label for="s-po">Porta</label><select id="s-po" v-model.number="form.smtp.port"><option :value="587">587</option><option :value="465">465</option><option :value="25">25</option><option :value="2525">2525</option></select></div>
          </div>
          <label class="check"><input v-model="form.smtp.auth" type="checkbox" /> <span>Servidor exige autenticação</span></label>
          <div v-if="form.smtp.auth" class="three">
            <div class="tm-field"><label for="s-u">Usuário</label><input id="s-u" v-model="form.smtp.username" autocomplete="off" /></div>
            <div class="tm-field"><label for="s-pw">Senha — somente escrita</label><input id="s-pw" v-model="secret.smtpPassword" type="password" autocomplete="new-password" :placeholder="setting?.value?.smtp?.password?.configured ? '•••••• (definida — preencha para substituir)' : ''" /></div>
            <div class="tm-field"><label for="s-ref">Ou segredo montado</label><input id="s-ref" v-model="secret.smtpPasswordFileRef" placeholder="file:smtp_password" /></div>
          </div>
          <div class="tm-hint">Certificado do servidor sempre validado. Destinos internos/metadata são bloqueados; relays internos são uma exceção configurada pela plataforma (BPM_MAIL_PRIVATE_RELAY_HOSTS).</div>
        </template>

        <template v-if="isM365">
          <h2>Microsoft 365 / Exchange Online</h2>
          <div class="tm-note" style="margin-top:0"><i class="pi pi-info-circle" aria-hidden="true" /><span>
            <template v-if="form.provider === 'm365_graph'">Graph sendMail com permissão de aplicação <code>Mail.Send</code> + admin consent, restrita à caixa de envio (RBAC for Applications / Application Access Policy no Exchange). Não usa senha de mailbox.</template>
            <template v-else>SMTP AUTH com OAuth (XOAUTH2) via <code>smtp.office365.com:587</code>: exige <code>SMTP.SendAsApp</code>, service principal registrado no Exchange e SMTP AUTH habilitado na caixa. Não presuma que o tenant permite.</template>
          </span></div>
          <div class="three">
            <div class="tm-field"><label for="o-t">Tenant ID</label><input id="o-t" v-model="form.m365.tenantId" /><div v-if="err('m365.tenantId')" class="tm-hint err">{{ err('m365.tenantId') }}</div></div>
            <div class="tm-field"><label for="o-c">Client ID do app de e-mail</label><input id="o-c" v-model="form.m365.clientId" /></div>
            <div class="tm-field"><label for="o-m">Caixa de envio (compartilhada)</label><input id="o-m" v-model="form.m365.senderMailbox" type="email" /></div>
          </div>
          <div class="two">
            <div class="tm-field"><label for="o-ct">Credencial do app</label><select id="o-ct" v-model="form.m365.credentialType"><option value="certificate">Certificado</option><option value="federated">Workload identity federation</option><option value="client_secret">Client secret</option></select>
              <div class="tm-hint">Atual: {{ setting?.value?.m365?.credential?.configured ? `definida (${setting?.value?.m365?.credential?.source})` : 'não definida' }}</div></div>
            <div class="tm-field"><label for="o-ref">Segredo montado (opcional)</label><input id="o-ref" v-model="secret.m365FileRef" placeholder="file:m365_mail_cert.pem" /></div>
          </div>
          <div v-if="form.m365.credentialType === 'certificate'" class="tm-field"><label for="o-pem">Certificado + chave (PEM) — somente escrita</label><textarea id="o-pem" v-model="secret.m365CertificatePem" spellcheck="false" /></div>
          <div v-if="form.m365.credentialType === 'client_secret'" class="tm-field"><label for="o-cs">Client secret — somente escrita</label><input id="o-cs" v-model="secret.m365ClientSecret" type="password" autocomplete="new-password" /></div>
          <label class="check"><input v-model="form.m365.checklist.mailSendPermission" type="checkbox" /> <span>Permissão concedida com admin consent pelo administrador do tenant</span></label>
          <label class="check"><input v-model="form.m365.checklist.mailboxRestricted" type="checkbox" /> <span>Acesso do app restrito à caixa de envio (Exchange)</span></label>
          <label v-if="form.provider === 'm365_smtp_oauth'" class="check"><input v-model="form.m365.checklist.smtpAuthEnabled" type="checkbox" /> <span>SMTP AUTH habilitado para a caixa</span></label>
        </template>

        <div v-if="form.provider === 'mailpit'" class="tm-note warn"><i class="pi pi-exclamation-triangle" aria-hidden="true" /><span>Mailpit captura as mensagens localmente (nenhuma entrega externa). Bloqueado em produção.</span></div>
        <div class="tm-actions" style="margin-top:12px"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar</button></div>
      </div>
    </section>

    <section class="tm-panel">
      <div class="tm-panel-head"><div class="tm-panel-title">Testes</div></div>
      <div class="tm-panel-body steps">
        <p class="tm-hint" style="margin:0">Salvar não executa testes. Use os botões abaixo (resultados ficam registrados e auditados).</p>
        <button class="tm-btn" :disabled="saving || form.provider === 'disabled'" @click="testConnection"><i class="pi pi-bolt" aria-hidden="true" /> Testar conexão</button>
        <div class="tm-field" style="margin:0"><label for="t-to">Enviar e-mail de teste para</label><input id="t-to" v-model="testTo" type="email" placeholder="seu.email@tmsa.example" /></div>
        <button class="tm-btn" :disabled="!testTo || form.provider === 'disabled'" @click="sendTest"><i class="pi pi-send" aria-hidden="true" /> Enviar teste</button>
        <div v-if="testMsg" class="tm-note" :class="testMsg.state === 'failed' ? 'err' : ''" role="status">
          <span><strong>{{ STATE_LABEL[testMsg.state] ?? testMsg.state }}</strong>
            <template v-if="testMsg.provider"> · {{ testMsg.provider }}</template>
            <template v-if="testMsg.durationMs != null"> · {{ testMsg.durationMs }} ms</template>
            <template v-if="testMsg.errorCode"> · código <code>{{ testMsg.errorCode }}</code></template>
            <br />Criado {{ fmtDateTime(testMsg.createdAt) }}<template v-if="testMsg.sentAt"> · enviado {{ fmtDateTime(testMsg.sentAt) }}</template>
            <template v-if="testMsg.providerMessageId"><br />ID no provedor: <code>{{ testMsg.providerMessageId }}</code></template>
            <template v-if="testMsg.error"><br />{{ testMsg.error }}</template>
            <br /><span class="tm-muted">Tentativas: {{ testMsg.attempts }} · detalhes de cada tentativa no log abaixo.</span>
          </span>
        </div>
      </div>
    </section>
  </div>

  <section class="tm-panel layout-panel">
    <div class="tm-panel-head">
      <div>
        <div class="tm-panel-title">Layout dos e-mails</div>
        <div class="tm-hint">Um modelo responsivo usado em notificações de etapas, aprovações, convites e mensagens operacionais.</div>
      </div>
    </div>
    <div class="tm-panel-body">
      <div class="layout-editor">
        <div class="layout-fields">
          <div class="tm-note"><i class="pi pi-image" aria-hidden="true" /><span>O cabeçalho usa automaticamente o <strong>Logo principal</strong> publicado em Organização e identidade visual. A imagem é carregada pela rota pública e versionada da própria plataforma.</span></div>
          <div class="tm-field">
            <label for="email-header-title">Identificação do cabeçalho</label>
            <input id="email-header-title" v-model="form.layout.headerTitle" maxlength="80" placeholder="BPM" />
          </div>
          <div class="tm-field">
            <label for="email-accent">Cor de destaque</label>
            <div class="color-control"><input id="email-accent" v-model="form.layout.accentColor" type="color" /><input v-model="form.layout.accentColor" aria-label="Código hexadecimal da cor" maxlength="7" /></div>
            <div v-if="err('layout.accentColor')" class="tm-hint err">{{ err('layout.accentColor') }}</div>
            <div class="tm-hint">A cor é verificada para manter contraste legível nos principais clientes de e-mail.</div>
          </div>
          <div class="tm-field">
            <label for="email-footer">Rodapé</label>
            <textarea id="email-footer" v-model="form.layout.footerText" maxlength="240" rows="3" placeholder="Mensagem automática do BPM. Não responda a este e-mail." />
            <div class="tm-hint">{{ form.layout.footerText.length }}/240 caracteres</div>
          </div>
          <div class="tm-field">
            <label for="email-link-help">Orientação para abrir o link</label>
            <input id="email-link-help" v-model="form.layout.linkHelpText" maxlength="160" />
          </div>
          <div class="tm-field">
            <label for="email-preview-type">Tipo de e-mail para editar e pré-visualizar</label>
            <select id="email-preview-type" v-model="previewSample">
              <option value="stage.completed">Avanço de etapa</option>
              <option value="task.assigned">Tarefa / aprovação pendente</option>
              <option value="instance.ended">Solicitação encerrada</option>
              <option value="identity.invitation">Convite de usuário externo</option>
              <option value="identity.password_reset">Redefinição de senha</option>
              <option value="admin.alert">Alerta operacional</option>
              <option value="email.test">E-mail de teste</option>
            </select>
          </div>
          <div v-if="selectedEmailText" class="message-customization">
            <div class="message-customization-title">Textos deste e-mail</div>
            <div class="tm-field">
              <label for="email-eyebrow">Texto de contexto</label>
              <input id="email-eyebrow" v-model="selectedEmailText.eyebrow" maxlength="80" />
            </div>
            <div class="tm-field">
              <label for="email-headline">Título principal</label>
              <input id="email-headline" v-model="selectedEmailText.headline" maxlength="140" />
            </div>
            <div class="tm-field">
              <label for="email-action-label">Texto do botão</label>
              <input id="email-action-label" v-model="selectedEmailText.actionLabel" maxlength="80" />
            </div>
            <div class="tm-field">
              <label for="email-body-intro">Mensagem complementar</label>
              <textarea id="email-body-intro" v-model="selectedEmailText.bodyIntro" maxlength="500" rows="3" placeholder="Opcional: uma orientação adicional antes dos detalhes da solicitação." />
              <div class="tm-hint">Até 500 caracteres. O conteúdo dinâmico da solicitação continua no e-mail.</div>
            </div>
          </div>
          <div class="tm-actions">
            <button class="tm-btn" :disabled="previewLoading" @click="refreshPreview"><i class="pi pi-eye" aria-hidden="true" /> {{ previewLoading ? 'Gerando prévia…' : 'Atualizar prévia' }}</button>
            <span v-if="previewRenderer" class="renderer-note"><i class="pi pi-check-circle" aria-hidden="true" /> Renderizador: {{ previewRenderer === 'carbone' ? 'Carbone' : 'template HTML padrão' }}</span>
          </div>
        </div>
        <div class="email-preview" aria-label="Pré-visualização do e-mail">
          <div v-if="!previewHtml" class="preview-empty"><i class="pi pi-envelope" aria-hidden="true" /> A prévia do e-mail aparecerá aqui.</div>
          <iframe v-else :key="previewHtml" :srcdoc="previewHtml" title="Prévia do layout do e-mail" sandbox="" />
        </div>
      </div>
      <div class="tm-actions layout-save"><button class="tm-btn primary" :disabled="saving" @click="submit">Salvar configuração e layout</button></div>
    </div>
  </section>

  <IntegrationLog
    area="email"
    title="Log de e-mail e notificações"
    caption="Cada teste (com as etapas e o tempo de cada uma), cada tentativa de entrega das notificações (código, motivo, próxima tentativa, Message-ID) e cada alteração da configuração. Clique em um registro para ver o que significa o erro e o que fazer. Sem senhas nem corpo das mensagens."
    :kind-labels="{ test: 'Testes', delivery: 'Entregas', config: 'Configuração' }"
    :refresh-key="logKey"
  />
</template>

<style scoped>
.grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
@media (max-width: 1300px) { .grid { grid-template-columns: 1fr; } }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.three { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
h2 { margin: 14px 0 8px; }
.check { display: flex; gap: 8px; align-items: flex-start; font-size: 12px; margin-bottom: 8px; color: #43586a; }
.steps { display: flex; flex-direction: column; gap: 9px; }
.layout-panel { margin: 16px 0; }
.layout-editor { display: grid; grid-template-columns: minmax(260px, 360px) minmax(0, 1fr); gap: 18px; align-items: start; }
.layout-fields { display: flex; flex-direction: column; gap: 12px; }
.layout-fields .tm-field { margin: 0; }
.message-customization { display: flex; flex-direction: column; gap: 10px; padding: 13px; border: 1px solid var(--line, #e7ebef); border-radius: 9px; background: #fbfcfd; }
.message-customization-title { font-size: 13px; font-weight: 700; color: var(--ink, #172230); }
.color-control { display: grid; grid-template-columns: 48px minmax(0, 1fr); gap: 8px; }
.color-control input[type="color"] { width: 48px; height: 38px; padding: 3px; }
.renderer-note { display: inline-flex; gap: 6px; align-items: center; color: var(--muted, #64748b); font-size: 12px; }
.email-preview { min-height: 580px; overflow: hidden; border: 1px solid var(--line, #e7ebef); border-radius: 10px; background: #f1f4f7; }
.email-preview iframe { display: block; width: 100%; height: 640px; border: 0; background: #f1f4f7; }
.preview-empty { min-height: 580px; display: grid; place-content: center; gap: 10px; text-align: center; color: var(--muted, #64748b); font-size: 13px; }
.preview-empty .pi { font-size: 28px; }
.layout-save { justify-content: flex-end; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line, #e7ebef); }
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
@media (max-width: 900px) { .layout-editor { grid-template-columns: 1fr; } .email-preview, .preview-empty { min-height: 480px; } .email-preview iframe { height: 520px; } }
</style>
