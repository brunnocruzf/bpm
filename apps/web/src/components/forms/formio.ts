/**
 * Integração Form.io (client-side, sem Form.io API Server).
 *  - Builder e Renderer embutidos em componentes Vue;
 *  - schemas e submissões persistidos pela API TMSA;
 *  - upload via provider de storage "tmsa" (API /attachments, URL assinada para download);
 *  - componente customizado "axlookup" (consulta AX sob demanda; o servidor reconsulta ao salvar);
 *  - painéis de JavaScript livre ocultos no builder (o servidor também remove essas propriedades).
 */
import { Components, Formio, Providers, Utils } from '@formio/js'
import { api, errorMessage } from '@/api/http'
import type { Meta } from '@/api/types'

let initialized = false
let uploadContext: { taskId?: string } = {}

export function setUploadContext(ctx: { taskId?: string }) {
  uploadContext = ctx
}

/** Renderer de execução: avaliação de JS desativada (noeval). Builder: habilitada (código interno do Form.io). */
export function setStrictEvaluation(strict: boolean) {
  ;(Utils as any).Evaluator.noeval = strict
}

export function fileService() {
  return new (Formio as any)(window.location.origin)
}

export const PT_BR = {
  pt: {
    required: '{{field}} é obrigatório.',
    pattern: '{{field}} não está no formato esperado.',
    maxLength: '{{field}} deve ter no máximo {{length}} caracteres.',
    minLength: '{{field}} deve ter no mínimo {{length}} caracteres.',
    min: '{{field}} deve ser maior ou igual a {{min}}.',
    max: '{{field}} deve ser menor ou igual a {{max}}.',
    invalid_email: '{{field}} deve ser um e-mail válido.',
    invalid_date: '{{field}} não é uma data válida.',
    error: 'Corrija os erros antes de enviar.',
    complete: 'Enviado com sucesso',
    submit: 'Enviar',
    'Add Another': 'Adicionar',
    'Adicionar ação': 'Adicionar ação',
    'Drop files to attach,': 'Arraste arquivos para anexar,',
    or: 'ou',
    browse: 'procure',
    'File Name': 'Arquivo',
    Size: 'Tamanho',
    'Type to search': 'Digite para buscar',
    'No results found': 'Nenhum resultado',
    'No choices to choose from': 'Nenhuma opção',
    'Press Enter to add': 'Enter para adicionar',
    'Drag and Drop a form component': 'Arraste um componente para o formulário',
    'Search field(s)': 'Buscar componente',
    Basic: 'Básicos',
    Advanced: 'Avançados',
    Layout: 'Layout',
    Data: 'Dados',
    Save: 'Salvar',
    Cancel: 'Cancelar',
    Remove: 'Remover',
    Preview: 'Pré-visualização',
    Help: 'Ajuda',
    Display: 'Exibição',
    Validation: 'Validação',
    API: 'API',
    Conditional: 'Condicional',
    Label: 'Rótulo',
    Placeholder: 'Texto de exemplo',
    Description: 'Descrição',
    Tooltip: 'Dica',
    'Property Name': 'Chave técnica',
    Required: 'Obrigatório',
    Hidden: 'Oculto',
    Disabled: 'Somente leitura',
    'Default Value': 'Valor padrão',
    'Data Source Values': 'Opções',
    Value: 'Valor',
    'Text Field': 'Texto curto',
    'Text Area': 'Texto longo',
    Number: 'Número',
    Checkbox: 'Caixa de seleção',
    'Select Boxes': 'Múltipla escolha',
    Select: 'Lista',
    Radio: 'Opções (rádio)',
    Email: 'E-mail',
    'Phone Number': 'Telefone',
    'Date / Time': 'Data / hora',
    Currency: 'Moeda',
    File: 'Arquivo',
    Panel: 'Painel',
    Columns: 'Colunas',
    'Field Set': 'Grupo de campos',
    Well: 'Destaque',
    Content: 'Conteúdo',
    'HTML Element': 'Elemento HTML',
    'Data Grid': 'Repetidor',
    Edit: 'Editar',
    Copy: 'Copiar',
    'Edit JSON': 'Editar JSON',
    'Move': 'Mover',
    'Simple Conditional': 'Condição simples',
    'This component should Display': 'Este componente deve ser exibido',
    'When the form component': 'Quando o campo',
    'Has the value': 'Tiver o valor',
    'Minimum Length': 'Tamanho mínimo',
    'Maximum Length': 'Tamanho máximo',
    'Regular Expression Pattern': 'Padrão (regex)',
    'Custom Properties': 'Propriedades personalizadas',
    Consultar: 'Consultar',
  },
}

export function setupFormio(meta: Meta | null) {
  if (initialized) return
  initialized = true

  // Provider de storage TMSA
  const TmsaStorage: any = function () {
    return {
      title: 'TMSA',
      name: 'tmsa',
      async uploadFile(file: File) {
        const fd = new FormData()
        fd.append('file', file, file.name)
        if (uploadContext.taskId) fd.append('taskId', uploadContext.taskId)
        const res = await api<{ file: Record<string, any> }>('/attachments', { method: 'POST', body: fd })
        return { ...res.file, originalName: file.name }
      },
      async downloadFile(file: any) {
        const res = await api<{ url: string }>(`/attachments/${file?.data?.attachmentId}/url`)
        return { ...file, url: res.url }
      },
      async deleteFile() {
        // o binário permanece até a limpeza de órfãos / política de retenção
        return true
      },
    }
  }
  TmsaStorage.title = 'TMSA'
  ;(Providers as any).addProvider('storage', 'tmsa', TmsaStorage)

  registerAxLookup(meta)
}

function registerAxLookup(meta: Meta | null) {
  const TextField = (Components as any).components.textfield
  const sources = meta?.lookupSources ?? []
  const allFields = Array.from(new Map(sources.flatMap((s) => s.fields.map((f) => [f.key, { label: `${f.label} (${f.key})`, value: f.key }]))).values())

  class AxLookupComponent extends TextField {
    static schema(...extend: any[]) {
      return TextField.schema({ type: 'axlookup', label: 'Consulta AX', key: 'consultaAx', lookupSource: sources[0]?.key ?? 'tpr', fillMap: [] }, ...extend)
    }

    static get builderInfo() {
      return { title: 'Consulta AX', group: 'tmsa', icon: 'search', weight: 5, schema: AxLookupComponent.schema() }
    }

    static editForm(...extend: any[]) {
      return TextField.editForm([
        {
          key: 'display',
          components: [
            {
              type: 'select', key: 'lookupSource', label: 'Fonte da consulta (AX ou API do catálogo de integrações)', weight: 1, input: true, dataSrc: 'values',
              data: { values: sources.map((s) => ({ label: s.label, value: s.key })) }, validate: { required: true },
            },
            {
              type: 'datagrid', key: 'fillMap', label: 'Preencher campos com o resultado', weight: 2, input: true, reorder: false,
              tooltip: 'O campo destino deve existir no formulário (normalmente somente leitura).',
              components: [
                { type: 'select', key: 'from', label: 'Dado do AX', input: true, dataSrc: 'values', data: { values: allFields } },
                { type: 'textfield', key: 'to', label: 'Chave do campo destino', input: true },
              ],
            },
          ],
        },
        ...extend,
      ])
    }

    get defaultSchema() {
      return AxLookupComponent.schema()
    }

    attach(element: HTMLElement) {
      const result = super.attach(element)
      const self = this as any
      const input: HTMLInputElement | undefined = self.refs?.input?.[0]
      if (!self.builderMode && !self.options.readOnly && !self.disabled && input?.parentNode && !input.parentNode.querySelector('.tm-axlookup-btn')) {
        const wrap = document.createElement('div')
        wrap.style.display = 'flex'
        wrap.style.alignItems = 'center'
        const btn = document.createElement('button')
        btn.type = 'button'
        btn.className = 'btn btn-outline-secondary btn-sm tm-axlookup-btn'
        btn.innerHTML = '<i class="bi bi-search"></i> Consultar'
        const status = document.createElement('div')
        status.className = 'tm-axlookup-status'
        input.parentNode.insertBefore(wrap, input)
        wrap.appendChild(input)
        wrap.appendChild(btn)
        wrap.after(status)
        self.addEventListener(btn, 'click', () => this.runLookup(status))
        self.addEventListener(input, 'change', () => this.runLookup(status))
      }

      return result
    }

    async runLookup(status: HTMLElement) {
      const self = this as any
      const value = String(self.getValue() ?? '').trim()
      if (!value) return
      status.className = 'tm-axlookup-status'
      status.textContent = 'Consultando AX...'
      try {
        const res = await api<{ found: boolean; data: Record<string, any>; transport: string }>(`/lookups/${self.component.lookupSource}`, { query: { value } })
        if (!res.found) {
          status.className += ' warn'
          status.textContent = `"${value}" não foi encontrado no AX.`
          return
        }
        for (const m of self.component.fillMap ?? []) {
          const target = self.root?.getComponent?.(m.to)
          target?.setValue(res.data[m.from] ?? '')
        }
        status.className += ' ok'
        status.textContent = `Dados do AX carregados (${res.transport}). O servidor confirma a consulta ao salvar.`
      } catch (e) {
        status.className += ' err'
        status.textContent = errorMessage(e)
      }
    }
  }

  ;(Components as any).addComponent('axlookup', AxLookupComponent)
}

const HIDE_CUSTOM_CODE = [
  { key: 'logic', ignore: true },
  { key: 'layout', ignore: true },
  {
    key: 'data',
    components: [
      { key: 'customDefaultValuePanel', ignore: true }, { key: 'calculateValuePanel', ignore: true },
      { key: 'calculateServer', ignore: true }, { key: 'allowCalculateOverride', ignore: true },
      { key: 'persistent', ignore: true }, { key: 'protected', ignore: true }, { key: 'dbIndex', ignore: true },
      { key: 'encrypted', ignore: true }, { key: 'redrawOn', ignore: true }, { key: 'dataSrc', ignore: true },
      { key: 'data.url', ignore: true }, { key: 'data.custom', ignore: true }, { key: 'template', ignore: true },
    ],
  },
  {
    key: 'validation',
    components: [
      { key: 'custom-validation-js', ignore: true }, { key: 'json-validation-json', ignore: true },
      { key: 'validate.customMessage', ignore: true }, { key: 'unique', ignore: true }, { key: 'validateOn', ignore: true },
    ],
  },
  { key: 'conditional', components: [{ key: 'customConditionalPanel', ignore: true }] },
  {
    key: 'api',
    components: [
      { key: 'tags', ignore: true },
      // marcação de dado sensível: mascarado em relatórios para quem não tem a permissão data.sensitive
      { type: 'checkbox', input: true, key: 'properties.tmsaSensitive', weight: 1, label: 'Campo sensível (mascarar em relatórios para quem não tem permissão)', tooltip: 'CPF, telefone e campos com dados pessoais já são tratados como sensíveis pelo nome/tipo.' },
    ],
  },
]

const FILE_EDIT = [
  ...HIDE_CUSTOM_CODE,
  {
    key: 'file',
    components: [
      { key: 'storage', ignore: true }, { key: 'url', ignore: true }, { key: 'dir', ignore: true }, { key: 'fileKey', ignore: true },
      { key: 'options', ignore: true }, { key: 'webcam', ignore: true }, { key: 'privateDownload', ignore: true },
      { key: 'uploadOnly', ignore: true }, { key: 'fileNameTemplate', ignore: true },
    ],
  },
]

const EDITABLE_TYPES = ['textfield', 'textarea', 'number', 'checkbox', 'selectboxes', 'select', 'radio', 'email', 'phoneNumber', 'datetime', 'currency', 'datagrid', 'panel', 'columns', 'fieldset', 'well', 'content', 'htmlelement', 'axlookup']

const actionPlanGrid = {
  type: 'datagrid', key: 'acoes', label: 'Plano de ação', input: true, addAnother: 'Adicionar ação', validate: { required: true },
  components: [
    { type: 'textfield', key: 'acao', label: 'Ação', input: true, validate: { required: true } },
    { type: 'textfield', key: 'responsavel', label: 'Responsável', input: true, validate: { required: true } },
    { type: 'datetime', key: 'prazo', label: 'Data-prazo', input: true, enableTime: false, format: 'dd/MM/yyyy', validate: { required: true } },
    { type: 'textfield', key: 'local', label: 'Local', input: true },
    { type: 'textfield', key: 'observacoes', label: 'Observações', input: true },
  ],
}

/** Opções do Builder: apenas componentes certificados + grupo TMSA. */
export function builderOptions() {
  return {
    language: 'pt',
    i18n: PT_BR,
    noDefaultSubmitButton: true,
    noNewEdit: false,
    builder: {
      premium: false,
      basic: {
        default: false,
        components: { password: false, button: false, textfield: true, textarea: true, number: true, checkbox: true, selectboxes: true, select: true, radio: true },
      },
      advanced: {
        components: {
          email: true, phoneNumber: true, datetime: true, currency: true, url: false, tags: false, address: false, day: false, time: false, survey: false, signature: false,
          file: { title: 'Arquivo', key: 'file', icon: 'file-earmark-arrow-up', schema: { type: 'file', label: 'Anexo', key: 'anexo', input: true, storage: 'tmsa', multiple: true, fileMaxSize: '20MB' } },
        },
      },
      layout: { components: { panel: true, columns: true, fieldset: true, well: true, htmlelement: true, content: true, table: false, tabs: false } },
      data: { components: { datagrid: true, hidden: false, container: false, datamap: false, editgrid: false, tree: false } },
      tmsa: {
        title: 'TMSA',
        weight: -10,
        default: true,
        components: {
          axlookup: true,
          yesNo: { title: 'Sim / Não', key: 'yesNo', icon: 'toggle-on', schema: { type: 'radio', label: 'Pergunta?', key: 'pergunta', input: true, inline: true, values: [{ label: 'Sim', value: 'sim' }, { label: 'Não', value: 'nao' }] } },
          autoUser: { title: 'Usuário logado', key: 'autoUser', icon: 'person-badge', schema: { type: 'textfield', label: 'Emissor', key: 'emissor', input: true, disabled: true, description: 'Preenchido automaticamente pelo sistema.', properties: { tmsaAuto: 'user.name' } } },
          autoArea: { title: 'Área do usuário', key: 'autoArea', icon: 'building', schema: { type: 'textfield', label: 'Área', key: 'areaUsuario', input: true, disabled: true, description: 'Preenchido automaticamente pelo sistema.', properties: { tmsaAuto: 'user.area' } } },
          autoDate: { title: 'Data atual', key: 'autoDate', icon: 'calendar-check', schema: { type: 'datetime', label: 'Data', key: 'dataRegistro', input: true, disabled: true, enableTime: false, format: 'dd/MM/yyyy', properties: { tmsaAuto: 'now.date' } } },
          autoSequence: { title: 'Número sequencial anual', key: 'autoSequence', icon: 'hash', schema: { type: 'textfield', label: 'Número', key: 'numeroDocumento', input: true, disabled: true, description: 'Gerado ao concluir (ex.: DOC-000001/26). Ajuste a chave em Propriedades: tmsaAuto = sequence:DOC.', properties: { tmsaAuto: 'sequence:DOC' } } },
          actionPlan: { title: 'Plano de ação (repetidor)', key: 'actionPlan', icon: 'list-check', schema: actionPlanGrid },
        },
      },
    },
    editForm: Object.fromEntries([...EDITABLE_TYPES.map((t) => [t, HIDE_CUSTOM_CODE]), ['file', FILE_EDIT]]),
  }
}

export { Formio }
