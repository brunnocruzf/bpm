<?php

namespace Database\Seeders\Blueprints;

use App\Flow\AssignmentRule;
use App\Flow\FlowBlueprint as B;
use App\Flow\PublishService;
use App\Forms\FieldCatalog;
use App\Forms\FormioBuilder as F;
use App\Identity\EmployeeDirectory;
use App\Models\BpmGroup;
use App\Models\IntegrationBinding;
use App\Models\ProcessCatalog;
use App\Models\ProcessStageVersion as S;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FOR-784 — Ocorrência de Ponto (adaptação do fluxo do SoftExpert).
 *
 * Instalado pela migration 2026_11_04_000100 (bases existentes) e pelo DatabaseSeeder (instalação nova).
 * Premissas [VALIDAR]:
 *  - aprova só a pessoa definida no formulário: o gestor substituto, se houver, ou o gestor imediato (ADR-0020).
 *    O grupo PONTO_GESTOR é a contingência quando ela não puder receber a tarefa e precisa ter membros;
 *  - a correção volta ao solicitante, com PONTO_SOLICITANTE como contingência;
 *  - nome e gestor imediato vêm da matrícula no cadastro de usuários (Entra: extensionAttribute1 e manager);
 *  - o substituto é indicado pelo solicitante pela matrícula e não pode ser ele mesmo nem o colaborador;
 *  - "O solicitante é gestor?" é calculado no servidor, comparando o solicitante com o gestor imediato;
 *  - o gestor tem 2 dias (timer P2D) para agir; sem decisão, a solicitação é reprovada automaticamente;
 *  - as colunas da grade "Ocorrência Ponto" e o retorno da integração (statusIntegracao = "sucesso")
 *    precisam ser confirmados com o RH e com o contrato do sistema de ponto;
 *  - o cancelamento do SoftExpert (evento de borda) virou a opção "Cancelar" na correção; o cancelamento
 *    automático "no mesmo dia" não foi modelado (a abertura já encaminha a solicitação);
 *  - as notificações do SoftExpert viraram o aviso por e-mail ao grupo da tarefa e o e-mail de
 *    encerramento ao solicitante (automático).
 */
final class For784
{
    public const KEY = 'for784_ocorrencia_ponto';

    /** Motivos da ocorrência (lista do formulário do SoftExpert). */
    public const MOTIVOS = [
        'esquecimento_registro' => 'Esquecimento do Registro',
        'cracha_nao_cadastrado' => 'Crachá Não Cadastrado',
        'perda_quebra_esquecimento_cracha' => 'Perda / Quebra / Esquecimento do Crachá',
        'problema_relogio' => 'Problema no Relógio',
    ];

    /** Etapa automática "Integração do Ponto": mapeamento personalizado, inativo até o vínculo com o endpoint. */
    public const INTEGRATION = 'map:integracaoPonto';

    /** Raias do FOR-784 (as do PO 159 ficam no DatabaseSeeder). */
    public const GROUPS = [
        'PONTO_SOLICITANTE' => ['Iniciador — Ponto', 'FOR-784: registra e corrige ocorrências de ponto'],
        'PONTO_GESTOR' => ['Gestor — Ponto', 'FOR-784: contingência da aprovação quando o gestor definido não puder receber a tarefa'],
        'RH' => ['RH', 'Recursos Humanos: aprovação do ponto e tratamento de erro de integração'],
        'PONTO_INTEGRACAO' => ['Integração — Ponto', 'FOR-784: raia da etapa automática (sem membros)'],
    ];

    /**
     * Cria raias, mapeamento e o fluxo publicado (idempotente). Se o fluxo já existe e o blueprint mudou,
     * publica uma nova versão. Sem usuário para autoria, não cria nem atualiza o fluxo.
     */
    public static function install(): ?ProcessCatalog
    {
        foreach (self::GROUPS as $key => [$name, $description]) {
            BpmGroup::firstOrCreate(['key' => $key], ['name' => $name, 'description' => $description]);
        }
        IntegrationBinding::firstOrCreate(['operation' => self::INTEGRATION], [
            'custom' => true,
            'kind' => 'task',
            'enabled' => false,
            'label' => 'Integração do Ponto (FOR-784)',
            'description' => 'Envia as ocorrências aprovadas ao sistema de ponto. A saída statusIntegracao deve devolver "sucesso" quando o lançamento for aceito; qualquer outro valor abre "Verificar Erro de Integração".',
            'inputs' => [
                ['key' => 'numeroSolicitacao', 'label' => 'Nº da solicitação'],
                ['key' => 'matriculaColaborador', 'label' => 'Matrícula do colaborador'],
                ['key' => 'nomeColaborador', 'label' => 'Nome do colaborador'],
                ['key' => 'ocorrencias', 'label' => 'Ocorrências de ponto'],
            ],
            'outputs' => [
                ['key' => 'statusIntegracao', 'label' => 'Situação da integração (sucesso | erro)'],
                ['key' => 'mensagemIntegracao', 'label' => 'Mensagem da integração'],
            ],
        ]);

        $owner = User::where('username', 'designer')->first()
            ?? User::where('auth_source', User::SOURCE_SUPERADMIN)->first()
            ?? User::orderBy('created_at')->first();
        if (! $owner) {
            return ProcessCatalog::where('key', self::KEY)->first();
        }
        $publisher = User::where('username', 'publisher')->first() ?? $owner;

        if ($catalog = ProcessCatalog::where('key', self::KEY)->first()) {
            // Já instalado: publica nova versão só quando o blueprint mudou (instâncias abertas seguem na versão delas).
            if (! B::differs($catalog, self::spec())) {
                return $catalog;
            }
            if ($catalog->draftVersion()) {
                Log::warning('FOR-784: há rascunho em edição; a atualização pelo blueprint não foi aplicada.');

                return $catalog;
            }
            $version = B::newVersion($catalog, self::spec(), $owner);
        } else {
            $catalog = B::create(self::spec(), $owner);
            $version = $catalog->latestVersion();
        }

        try {
            app(PublishService::class)->publish($version, $publisher);
        } catch (Throwable $e) {
            // política de publicação do ambiente (ex.: publicador distinto): o rascunho fica para publicar pela tela
            Log::warning("FOR-784 v{$version->version} criado como rascunho; publique pelo editor.", ['error' => $e->getMessage()]);
        }

        return $catalog->fresh();
    }

    public static function spec(): array
    {
        // Abertura: campos reaproveitados (somente leitura) nas etapas seguintes.
        // Matrícula consultada no cadastro de usuários (Entra): traz o nome e o gestor imediato vinculado.
        $matricula = F::lookup('matriculaColaborador', 'Matrícula do colaborador', EmployeeDirectory::SOURCE,
            ['name' => 'nomeColaborador', 'managerName' => 'gestorImediato', 'managerEmail' => 'gestorImediatoEmail', 'userId' => 'colaboradorUserId', 'managerUserId' => 'gestorImediatoId'],
            ['required' => true, 'maxLength' => 20, 'description' => 'Digite a matrícula (ex.: 4085 ou 014085 — 01 TMSA, 02 IMS) e consulte.']);
        $nome = F::text('nomeColaborador', 'Nome do colaborador', ['disabled' => true]);
        $gestor = F::text('gestorImediato', 'Gestor imediato', ['disabled' => true, 'description' => 'Gestor vinculado no Entra. Se vier vazio, marque "Substituir gestor".']);
        $gestorEmail = F::text('gestorImediatoEmail', 'E-mail do gestor imediato', ['disabled' => true]);
        $gestorSubstituto = F::text('gestorSubstituto', 'Gestor substituto', ['maxLength' => 120]);
        // Quem aprova no lugar do gestor imediato: a consulta garante que a pessoa existe no sistema.
        $matriculaSubstituto = F::lookup('matriculaSubstituto', 'Matrícula do gestor substituto', EmployeeDirectory::SOURCE,
            ['userId' => 'gestorSubstitutoId', 'name' => 'gestorSubstituto'],
            ['required' => true, 'maxLength' => 20, 'when' => ['substituirGestor' => true], 'description' => 'Quem aprova no lugar do gestor imediato (ausência, férias...). Digite a matrícula e consulte.']);
        $hora = fn (string $key, string $label) => F::time($key, $label);
        $avisoNoturno = 'Se a jornada de trabalho inicia em um dia e termina no dia seguinte, deve ser selecionado o campo Noturno.';
        $ocorrencias = F::datagrid('ocorrencias', 'Ocorrência Ponto', [
            F::select('motivo', 'Motivo', self::MOTIVOS, ['required' => true, 'placeholder' => 'Escolha um item']),
            F::date('dataOcorrencia', 'Data', ['required' => true]),
            $hora('entrada', 'Entrada'),
            $hora('saida', 'Saída'),
            F::checkbox('noturno', 'Noturno', ['tooltip' => $avisoNoturno]),
        ], ['required' => true, 'addAnother' => 'Adicionar ocorrência', 'description' => 'Lance somente as datas pendentes. '.$avisoNoturno]);
        $ajuste = fn (array $grid) => F::panel('Dados do colaborador para ajuste do ponto', [$grid], ['key' => 'panel_ajuste_ponto']);

        $copied = fn (array ...$components) => array_map([F::class, 'copied'], $components);
        $solicitacao = F::panel('Solicitação', [
            F::columns([
                $copied(F::text('numeroSolicitacao', 'Nº da solicitação')),
                $copied(F::text('criadoPor', 'Criado por')),
                $copied(F::date('criadoEm', 'Criado em')),
            ]),
            F::columns([$copied($matricula), $copied($nome)]),
            F::columns([$copied($gestor), $copied($gestorSubstituto)]),
        ], ['key' => 'panel_solicitacao']);
        $decisao = fn (string $key, string $label) => F::radio($key, $label, [
            'aprovado' => 'Aprovar',
            'correcao' => 'Devolver para correção',
            'reprovado' => 'Reprovar',
        ], ['required' => true]);
        $motivos = fn (string $decisionKey) => [
            F::textarea('motivoCorrecao', 'O que precisa ser corrigido', ['required' => true, 'when' => [$decisionKey => 'correcao']]),
            F::textarea('motivoReprovacao', 'Motivo da reprovação', ['required' => true, 'when' => [$decisionKey => 'reprovado']]),
        ];
        $avisoTarefa = ['enabled' => true, 'groupPolicy' => 'members', 'onReassign' => true];

        return [
            'key' => self::KEY,
            'name' => 'FOR-784 - Ocorrência de Ponto',
            'category' => 'RH',
            'prefix' => 'OCP',
            'description' => 'Registro de ocorrências (ajustes) de ponto do colaborador, com aprovação do gestor imediato e do RH e integração com o sistema de ponto.',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START, 'lane' => 'PONTO_SOLICITANTE'],
                [
                    'key' => 'registrar_marcacoes', 'name' => 'Registrar Marcações', 'type' => S::USER_TASK, 'lane' => 'PONTO_SOLICITANTE',
                    'description' => 'Informe o colaborador e lance somente as datas pendentes.',
                    'form' => ['title' => 'Registro de Ponto', 'components' => [
                        F::panel('Solicitante', [
                            F::columns([
                                [F::auto('criadoPor', 'Criado por', 'instance.requesterName')],
                                [F::auto('criadoEm', 'Criado em', 'instance.startedAt')],
                                [F::auto('numeroSolicitacao', 'Nº da solicitação', 'instance.number')],
                            ]),
                        ], ['key' => 'panel_solicitante']),
                        F::panel('Colaborador', [
                            F::columns([[$matricula], [$nome]]),
                            F::columns([[$gestor], [$gestorEmail], [F::checkbox('substituirGestor', 'Substituir gestor')]]),
                            F::columns([[$matriculaSubstituto], [F::text('gestorSubstituto', 'Gestor substituto', ['disabled' => true, 'maxLength' => 120, 'when' => ['substituirGestor' => true]])]]),
                            F::auto('solicitanteGestor', 'Solicitante é o gestor imediato?', FieldCatalog::REQUESTER_MATCHES.'gestorImediatoId', [
                                'description' => 'Calculado pelo sistema ao enviar. Se sim, a solicitação segue direto para o RH.']),
                        ], ['key' => 'panel_colaborador']),
                        F::content('<div class="alert alert-danger"><p><strong>OBS:</strong></p><p><strong>1) Lance somente as datas pendentes.</strong></p><p><strong>2) Após o lançamento, clique em "Abrir solicitação".</strong></p><p><strong>3) Sem decisão do gestor em 2 dias, a solicitação é reprovada automaticamente.</strong></p></div>', 'content_obs_ponto'),
                        $ajuste($ocorrencias),
                    ]],
                ],
                ['key' => 'g_solicitante_gestor', 'name' => 'O solicitante é gestor?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PONTO_SOLICITANTE'],

                // Gestor: aprovação em paralelo com o prazo (timer) — sem decisão no prazo, reprovação automática.
                ['key' => 'p_gestor', 'name' => 'Aprovação do gestor com prazo', 'type' => S::PARALLEL_GATEWAY, 'lane' => 'PONTO_GESTOR'],
                [
                    'key' => 'aprovar_gestor', 'name' => 'Aprovar Registro de Ponto — Gestor', 'type' => S::USER_TASK, 'lane' => 'PONTO_GESTOR',
                    'config' => ['slaDays' => 1, 'notifyAssignee' => $avisoTarefa, 'assignment' => [
                        'mode' => AssignmentRule::FIELD,
                        'fields' => ['gestorSubstitutoId', 'gestorImediatoId'],
                        'fallbackGroup' => 'PONTO_GESTOR',
                        'excludeRequester' => true,
                        'excludeFields' => ['colaboradorUserId'],
                        'requiredUnless' => ['solicitanteGestor' => 'sim'],
                    ]],
                    'description' => 'Aprove, devolva para correção ou reprove. Sem decisão em 2 dias, a solicitação é reprovada automaticamente.',
                    'form' => ['title' => 'Aprovar Registro de Ponto — Gestor', 'components' => [
                        $solicitacao,
                        $ajuste(F::copied($ocorrencias)),
                        $decisao('decisaoGestor', 'Decisão do gestor'),
                        ...$motivos('decisaoGestor'),
                        F::textarea('parecerGestor', 'Observações do gestor'),
                    ]],
                ],
                ['key' => 'g_decisao_gestor', 'name' => 'Decisão do gestor', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PONTO_GESTOR'],
                ['key' => 't_prazo_gestor', 'name' => 'Prazo do gestor (2 dias)', 'type' => S::TIMER, 'lane' => 'PONTO_GESTOR', 'config' => ['mode' => 'duration', 'duration' => 'P2D']],
                ['key' => 'g_acao_gestor', 'name' => 'Ação do gestor', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PONTO_GESTOR'],
                ['key' => 'fim_prazo_gestor', 'name' => 'Fim', 'type' => S::END, 'lane' => 'PONTO_GESTOR', 'config' => ['result' => 'concluido']],

                // RH
                [
                    'key' => 'aprovar_rh', 'name' => 'Aprovar Registro de Ponto — RH', 'type' => S::USER_TASK, 'lane' => 'RH',
                    'config' => ['slaDays' => 2, 'notifyAssignee' => $avisoTarefa],
                    'form' => ['title' => 'Aprovar Registro de Ponto — RH', 'components' => [
                        $solicitacao,
                        $ajuste(F::copied($ocorrencias)),
                        F::panel('Aprovação do gestor', $copied(
                            F::radio('decisaoGestor', 'Decisão do gestor', ['aprovado' => 'Aprovar', 'correcao' => 'Devolver para correção', 'reprovado' => 'Reprovar']),
                            F::textarea('parecerGestor', 'Observações do gestor'),
                        ), ['key' => 'panel_aprovacao_gestor']),
                        $decisao('decisaoRh', 'Decisão do RH'),
                        ...$motivos('decisaoRh'),
                        F::textarea('parecerRh', 'Observações do RH'),
                    ]],
                ],
                ['key' => 'g_decisao_rh', 'name' => 'Decisão do RH', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'RH'],

                // Correção pelo solicitante
                [
                    'key' => 'corrigir_marcacoes', 'name' => 'Corrigir Marcações', 'type' => S::USER_TASK, 'lane' => 'PONTO_SOLICITANTE',
                    'config' => ['slaDays' => 1, 'notifyAssignee' => $avisoTarefa, 'assignment' => ['mode' => AssignmentRule::REQUESTER, 'fallbackGroup' => 'PONTO_SOLICITANTE']],
                    'description' => 'Ajuste as ocorrências conforme a orientação e reenvie, ou cancele a solicitação.',
                    'form' => ['title' => 'Corrigir Marcações', 'components' => [
                        F::copied(F::textarea('motivoCorrecao', 'O que precisa ser corrigido')),
                        $solicitacao,
                        $ajuste($ocorrencias),
                        F::radio('acaoCorrecao', 'O que deseja fazer?', ['reenviar' => 'Reenviar para aprovação', 'cancelar' => 'Cancelar a solicitação'], ['required' => true]),
                    ]],
                ],
                ['key' => 'g_retorno_correcao', 'name' => 'Reenviar ou cancelar?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PONTO_SOLICITANTE'],
                ['key' => 'fim_cancelado', 'name' => 'Cancelado', 'type' => S::END, 'lane' => 'PONTO_SOLICITANTE', 'config' => ['result' => 'cancelado', 'terminate' => true]],

                // Integração
                ['key' => 'integracao_ponto', 'name' => 'Integração do Ponto', 'type' => S::SERVICE_TASK, 'lane' => 'PONTO_INTEGRACAO', 'config' => ['operation' => self::INTEGRATION]],
                ['key' => 'g_resultado_integracao', 'name' => 'Integração concluída?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PONTO_INTEGRACAO'],
                [
                    'key' => 'verificar_erro_integracao', 'name' => 'Verificar Erro de Integração', 'type' => S::USER_TASK, 'lane' => 'RH',
                    'config' => ['slaDays' => 1, 'notifyAssignee' => $avisoTarefa],
                    'description' => 'O sistema de ponto recusou o lançamento. Corrija a causa e envie novamente.',
                    'form' => ['title' => 'Verificar Erro de Integração', 'components' => [
                        F::columns([
                            $copied(F::text('statusIntegracao', 'Situação da integração')),
                            $copied(F::textarea('mensagemIntegracao', 'Mensagem da integração')),
                        ]),
                        $solicitacao,
                        $ajuste(F::copied($ocorrencias)),
                        F::textarea('providenciaErroIntegracao', 'Providência tomada antes de enviar novamente', ['required' => true]),
                    ]],
                ],
                ['key' => 'fim_concluido', 'name' => 'Fim', 'type' => S::END, 'lane' => 'PONTO_INTEGRACAO', 'config' => ['result' => 'concluido', 'terminate' => true]],
                ['key' => 'fim_reprovado', 'name' => 'Reprovado', 'type' => S::END, 'lane' => 'PONTO_GESTOR', 'config' => ['result' => 'reprovado', 'terminate' => true]],
            ],
            'transitions' => [
                ['inicio', 'registrar_marcacoes'],
                ['registrar_marcacoes', 'g_solicitante_gestor'],
                ['g_solicitante_gestor', 'aprovar_rh', ['when' => B::when(['solicitanteGestor' => 'sim']), 'label' => 'Sim']],
                ['g_solicitante_gestor', 'p_gestor', ['default' => true, 'label' => 'Não']],

                ['p_gestor', 'aprovar_gestor'],
                ['p_gestor', 't_prazo_gestor'],
                ['aprovar_gestor', 'g_decisao_gestor'],
                ['g_decisao_gestor', 'aprovar_rh', ['when' => B::when(['decisaoGestor' => 'aprovado']), 'label' => 'Aprovado']],
                ['g_decisao_gestor', 'corrigir_marcacoes', ['when' => B::when(['decisaoGestor' => 'correcao']), 'label' => 'Correção']],
                ['g_decisao_gestor', 'fim_reprovado', ['default' => true, 'label' => 'Reprovado']],
                ['t_prazo_gestor', 'g_acao_gestor'],
                ['g_acao_gestor', 'fim_prazo_gestor', ['when' => ['match' => 'all', 'rules' => [['field' => 'decisaoGestor', 'operator' => 'not_empty', 'value' => null]]], 'label' => 'Gestor agiu']],
                ['g_acao_gestor', 'fim_reprovado', ['default' => true, 'label' => 'Reprovação automática']],

                ['aprovar_rh', 'g_decisao_rh'],
                ['g_decisao_rh', 'integracao_ponto', ['when' => B::when(['decisaoRh' => 'aprovado']), 'label' => 'Aprovado']],
                ['g_decisao_rh', 'corrigir_marcacoes', ['when' => B::when(['decisaoRh' => 'correcao']), 'label' => 'Correção']],
                ['g_decisao_rh', 'fim_reprovado', ['default' => true, 'label' => 'Reprovado']],

                ['corrigir_marcacoes', 'g_retorno_correcao'],
                ['g_retorno_correcao', 'fim_cancelado', ['when' => B::when(['acaoCorrecao' => 'cancelar']), 'label' => 'Cancelar']],
                ['g_retorno_correcao', 'aprovar_rh', ['when' => B::when(['acaoCorrecao' => 'reenviar', 'solicitanteGestor' => 'sim']), 'label' => 'Solicitante é gestor']],
                ['g_retorno_correcao', 'aprovar_gestor', ['default' => true, 'label' => 'Reenviar ao gestor']],

                ['integracao_ponto', 'g_resultado_integracao'],
                ['g_resultado_integracao', 'fim_concluido', ['when' => B::when(['statusIntegracao' => 'sucesso']), 'label' => 'Sucesso']],
                ['g_resultado_integracao', 'verificar_erro_integracao', ['default' => true, 'label' => 'Erro']],
                ['verificar_erro_integracao', 'integracao_ponto', ['label' => 'Enviar novamente']],
            ],
        ];
    }
}
