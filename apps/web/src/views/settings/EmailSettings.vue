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

const form = reactive({
  provider: 'disabled', fromAddress: '', fromName: '', replyTo: '', timeout: 15,
  smtp: { host: '', port: 587, encryption: 'starttls', auth: true, username: '' },
  m365: { tenantId: '', clientId: '', credentialType: 'certificate', senderMailbox: '', saveToSentItems: false, checklist: { mailSendPermission: false, mailboxRestricted: false, smtpAuthEnabled: false } as Record<string, boolean> },
})
const secret = reactive({ smtpPassword: '', smtpPasswordFileRef: '', m365CertificatePem: '', m365ClientSecret: '', m365FileRef: '' })
const testTo = ref('')
interface TestMsg { id: string; state: string; provider: string | null; providerMessageId: string | null; errorCode: string | null; error: string | null; attempts: number; createdAt: string; sentAt: string | null; durationMs: number | null }
const testMsg = ref<TestMsg | null>(null)
// recarrega o log depois de salvar/testar
const logKey = ref(0)
let poll: number | undefined

const providers = computed<{ key: string; label: string; available: boolean }[]>(() => setting.value?.value?.providers ?? [])
const isM365 = computed(() => form.provider === 'm365_graph' || form.provider === 'm365_smtp_oauth')

function fill() {
  const v = setting.value?.value
  if (!v) return
  Object.assign(form, {
    provider: v.provider, fromAddress: v.fromAddress, fromName: v.fromName, replyTo: v.replyTo, timeout: v.timeout,
    smtp: { host: v.smtp.host, port: v.smtp.port, encryption: v.smtp.encryption, auth: v.smtp.auth, username: v.smtp.username },
    m365: { tenantId: v.m365.tenantId, clientId: v.m365.clientId, credentialType: v.m365.credentialType, senderMailbox: v.m365.senderMailbox, saveToSentItems: v.m365.saveToSentItems, checklist: { ...form.m365.checklist, ...(v.m365.checklist ?? {}) } },
  })
}
watch(setting, fill)
onMounted(load)
onBeforeUnmount(() => window.clearTimeout(poll))

watch(() => form.smtp.encryption, (e) => {
  if (e === 'tls' && form.smtp.port === 587) form.smtp.port = 465
  if (e === 'starttls' && form.smtp.port === 465) form.smtp.port = 587
})

async function submit() {
  const body: any = { provider: form.provider, fromAddress: form.fromAddress, fromName: form.fromName, replyTo: form.replyTo, timeout: form.timeout, smtp: { ...form.smtp }, m365: { ...form.m365 } }
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
.err { color: var(--red); }
code { font-size: 11px; background: #eef2f5; padding: 1px 4px; border-radius: 4px; }
</style>
