<?php

namespace Database\Seeders\Blueprints;

use App\Flow\FlowBlueprint as B;
use App\Forms\FormioBuilder as F;
use App\Models\ProcessStageVersion as S;

/**
 * PO 159 — Item Reprovado no Recebimento (especificação funcional de 08/07/2026).
 *
 * Versão de trabalho para oficina de validação. Premissas marcadas com [VALIDAR]
 * estão listadas em docs/po159/modelo-po159.md:
 *  - 24 e 29 ("Procede?") modeladas como uma única tarefa do fornecedor (reanálise após contestação);
 *  - retorno da atividade 10 (Comitê NP) segue para revisão do RACF (11);
 *  - 32 é uma tarefa própria (GEF revisa o RACF) que retorna à análise do fornecedor (30);
 *  - loop 30.3 com timer de 30 dias e limite de 6 ciclos;
 *  - FOB aguarda a data de coleta (timer) antes do faturamento;
 *  - SLAs (slaDays) são provisórios.
 */
final class Po159
{
    public static function spec(): array
    {
        // Campos da abertura (01), reutilizados como cópia somente leitura nas etapas seguintes.
        $nCan = F::text('nCan', 'Nº CAN', ['required' => true, 'description' => 'Certificado de análise.', 'maxLength' => 60]);
        $tpr = F::lookup('tpr', 'TPR', 'tpr', ['customerName' => 'cliente'], ['required' => true, 'description' => 'Consulte para preencher o cliente pelo AX.']);
        $cliente = F::text('cliente', 'Cliente (AX)', ['disabled' => true]);
        $codigoItem = F::lookup('codigoItem', 'Código do item', 'item', ['itemDescription' => 'descricaoMaterial'], ['required' => true]);
        $descricaoMaterial = F::text('descricaoMaterial', 'Descrição do material (AX)', ['disabled' => true]);
        $nf = F::number('nf', 'NF', ['required' => true]);
        $ordemCompra = F::lookup('ordemCompra', 'Ordem de compra', 'purchaseOrder', ['supplierCode' => 'fornecedorCodigo', 'supplierName' => 'fornecedorNome'], ['required' => true]);
        $fornecedorCodigo = F::text('fornecedorCodigo', 'Código do fornecedor (AX)', ['disabled' => true]);
        $fornecedorNome = F::text('fornecedorNome', 'Fornecedor (AX)', ['disabled' => true]);

        $tipoItem = F::select('tipoItem', 'Tipo de item', [
            '01' => 'Tipo 01 — MP/componente comprado (→ SUP)',
            '03' => 'Tipo 03 — Industrializado com OP (→ PPCPM)',
            '09' => 'Tipo 09 — Manufaturado com OP (→ PPCPM)',
        ], ['required' => true]);

        $numeroRacf = F::auto('numeroRacf', 'Nº RACF', 'sequence:RACF');
        $prazoResposta = F::date('prazoResposta', 'Prazo de resposta do fornecedor', ['required' => true]);
        $decisao = F::select('decisaoImediata', 'Decisão imediata', ['devolucao' => 'Devolução', 'retrabalho' => 'Retrabalho', 'liberado_condicional' => 'Liberado condicional'], ['required' => true]);
        $responsavelLib = F::text('responsavelLiberacao', 'Responsável pela liberação condicional', ['required' => true, 'when' => ['decisaoImediata' => 'liberado_condicional'], 'description' => 'Normalmente o avaliador do Comitê (atividades 09/10).']);
        $descricaoProblema = F::textarea('descricaoProblema', 'Descrição do problema', ['required' => true]);
        $observacoesRacf = F::textarea('observacoesRacf', 'Observações');

        $justificativaNp = F::textarea('justificativaNaoProcede', 'Justificativa da não procedência', ['required' => true, 'when' => ['procede' => 'nao']]);
        $acoes = F::datagrid('acoes', 'Plano de ação corretivo', [
            F::text('acao', 'Ação', ['required' => true]),
            F::text('responsavel', 'Responsável', ['required' => true]),
            F::date('prazo', 'Data-prazo', ['required' => true]),
            F::text('local', 'Local'),
            F::text('observacoes', 'Observações'),
        ], ['required' => true, 'description' => 'Cada ação será executada e concluída individualmente na atividade 30.1, com evidência.']);

        $copied = fn (array ...$components) => array_map([F::class, 'copied'], $components);

        return [
            'key' => 'item_reprovado_recebimento',
            'name' => 'Item Reprovado no Recebimento',
            'category' => 'Qualidade',
            'prefix' => 'IRR',
            'description' => 'PO 159 — tratativa de itens não conformes detectados no recebimento: análise, RACF, plano de ação do fornecedor e devolução/garantia.',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START, 'lane' => 'CQ'],
                [
                    'key' => 'a01_abertura', 'name' => '01 — Item reprovado no recebimento', 'type' => S::USER_TASK, 'lane' => 'CQ',
                    'description' => 'Registre o item reprovado. Use "Consultar" para trazer dados do AX.',
                    'form' => ['title' => 'Item reprovado no recebimento', 'components' => [
                        F::columns([[$nCan], [$nf]]),
                        F::columns([[$tpr], [$cliente]]),
                        F::columns([[$codigoItem], [$descricaoMaterial]]),
                        F::columns([[$ordemCompra], [$fornecedorNome]]),
                        $fornecedorCodigo,
                    ]],
                ],
                [
                    'key' => 'a05_analise_previa', 'name' => '05–08 — Análise prévia', 'type' => S::USER_TASK, 'lane' => 'PPCPM', 'config' => ['slaDays' => 2],
                    'description' => 'Defina o tipo de item e se o caso é urgente. A integração SENF por tipo não é executada automaticamente nesta versão.',
                    'form' => ['title' => 'Análise prévia — PPCPM', 'components' => [
                        F::panel('Dados da abertura', $copied($nCan, $codigoItem, $descricaoMaterial, $cliente)),
                        $tipoItem,
                        F::yesNo('urgente', 'É urgente? (06)', ['required' => true]),
                        F::date('dataPrazo', 'Data prazo (07)', ['required' => true, 'when' => ['urgente' => 'sim']]),
                        F::textarea('justificativaUrgencia', 'Justificativa da urgência (08)', ['required' => true, 'when' => ['urgente' => 'sim']]),
                    ]],
                ],
                ['key' => 'g06_urgente', 'name' => 'É urgente?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'PPCPM'],
                [
                    'key' => 'a09_comite_urgencia', 'name' => '09 — Avaliação de urgência', 'type' => S::USER_TASK, 'lane' => 'COMITE',
                    // demonstração: e-mail ao Comitê quando a urgência chega (template padrão; demais etapas desligadas)
                    'config' => ['slaDays' => 2, 'notifyOnComplete' => ['CQ', 'PPCPM'], 'notifyAssignee' => ['enabled' => true, 'groupPolicy' => 'members', 'onReassign' => true]],
                    'form' => ['title' => 'Avaliação de urgência pelo Comitê', 'components' => [
                        F::panel('Pedido de urgência', $copied($nCan, $descricaoMaterial, F::date('dataPrazo', 'Data prazo'), F::textarea('justificativaUrgencia', 'Justificativa'))),
                        F::textarea('avaliacaoUrgencia', 'Avaliação do Comitê', ['required' => true]),
                        F::columns([[F::auto('avaliadorUrgencia', 'Avaliador', 'user.name')], [F::auto('dataAvaliacaoUrgencia', 'Data da avaliação', 'now.date')]]),
                    ]],
                ],
                [
                    'key' => 'a11_gerar_racf', 'name' => '11 — Gerar RACF', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 3],
                    'description' => 'Complete o Relatório de Ação Corretiva de Fornecedor. Dados da abertura são copiados automaticamente.',
                    'form' => ['title' => 'RACF — Relatório de ação corretiva de fornecedor', 'components' => [
                        F::panel('Identificação', [
                            F::columns([[$numeroRacf], [F::auto('dataRacf', 'Data', 'now.date')]]),
                            F::columns([[F::auto('emissorRacf', 'Emissor', 'user.name')], [F::auto('areaEmissor', 'Área do emissor', 'user.area')]]),
                        ]),
                        F::panel('Dados da abertura (atividade 01)', [
                            F::columns([$copied($nCan, $tpr, $cliente), $copied($codigoItem, $descricaoMaterial, $nf, $ordemCompra)]),
                        ]),
                        F::panel('Fornecedor', [
                            F::lookup('fornecedorCodigo', 'Código do fornecedor', 'supplier', [
                                'supplierName' => 'fornecedorNome', 'contactName' => 'fornecedorContato', 'phone' => 'fornecedorTelefone', 'email' => 'fornecedorEmail',
                            ], ['required' => true, 'description' => 'Pré-preenchido pela OC; consulte para atualizar o contato.']),
                            F::columns([[$fornecedorNome, F::text('fornecedorContato', 'Contato (AX)', ['disabled' => true])], [F::text('fornecedorTelefone', 'Telefone (AX)', ['disabled' => true]), F::text('fornecedorEmail', 'E-mail (AX)', ['disabled' => true])]]),
                        ]),
                        F::columns([[$prazoResposta], [F::date('dataRespostaFornecedor', 'Data de resposta do fornecedor')]]),
                        F::columns([[$decisao], [$responsavelLib]]),
                        F::yesNo('reincidente', 'Reincidente?', ['required' => true]),
                        $descricaoProblema,
                        $observacoesRacf,
                    ]],
                ],
                [
                    'key' => 'a24_analise_fornecedor', 'name' => '24/29 — Análise do RACF pelo fornecedor', 'type' => S::USER_TASK, 'lane' => 'FORNECEDOR', 'config' => ['slaDays' => 5],
                    'description' => 'Fornecedor (ou SUP em seu nome, enquanto o acesso externo não estiver aprovado) informa se o RACF procede.',
                    'form' => ['title' => 'Análise do RACF pelo fornecedor', 'components' => [
                        F::panel('RACF', $copied(F::text('numeroRacf', 'Nº RACF'), $descricaoProblema, $decisao, $prazoResposta)),
                        F::yesNo('procede', 'O RACF procede?', ['required' => true]),
                        $justificativaNp,
                    ]],
                ],
                ['key' => 'g24_procede', 'name' => 'Procede?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'FORNECEDOR'],
                [
                    'key' => 'a26_gef_np', 'name' => '26–28 — GEF avalia a não procedência', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 3],
                    'form' => ['title' => 'Avaliação da não procedência — GEF', 'components' => [
                        F::copied(F::textarea('justificativaNaoProcede', 'Justificativa do fornecedor')),
                        F::yesNo('gefConcordaNp', 'GEF concorda com a não procedência? (26)', ['required' => true]),
                        F::yesNo('necessitaComite', 'Necessita avaliação do Comitê? (27/28)', ['required' => true, 'when' => ['gefConcordaNp' => 'nao']]),
                        F::textarea('parecerGef', 'Parecer da GEF'),
                    ]],
                ],
                ['key' => 'g26_concorda', 'name' => 'GEF concorda com a NP?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'GEF'],
                ['key' => 'end_np', 'name' => 'Encerrado — não procedente', 'type' => S::END, 'lane' => 'GEF', 'config' => ['result' => 'nao_procedente', 'notify' => ['CQ', 'SUP']]],
                [
                    'key' => 'a10_comite_np', 'name' => '10 — Avaliação de não procedência', 'type' => S::USER_TASK, 'lane' => 'COMITE',
                    'config' => ['slaDays' => 3, 'notifyOnComplete' => ['CQ', 'PPCPM', 'GEF']],
                    'form' => ['title' => 'Avaliação de não procedência pelo Comitê', 'components' => [
                        F::panel('Contestação', $copied(F::textarea('justificativaNaoProcede', 'Justificativa do fornecedor'), F::textarea('parecerGef', 'Parecer da GEF'))),
                        F::textarea('avaliacaoNp', 'Avaliação do Comitê', ['required' => true]),
                        F::columns([[F::auto('avaliadorNp', 'Avaliador', 'user.name')], [F::auto('dataAvaliacaoNp', 'Data da avaliação', 'now.date')]]),
                    ]],
                ],
                ['key' => 'p_fork', 'name' => 'Abrir trilhas paralelas', 'type' => S::PARALLEL_GATEWAY, 'lane' => 'FORNECEDOR'],
                // Trilha A — causa raiz e plano de ação
                [
                    'key' => 'a30_analise_racf', 'name' => '30 — Analisar RACF: causa raiz e plano', 'type' => S::USER_TASK, 'lane' => 'FORNECEDOR',
                    'config' => ['slaDays' => 10, 'actionPlan' => ['mode' => 'capture', 'field' => 'acoes'], 'notifyOnComplete' => ['GEF']],
                    'form' => ['title' => 'Análise do RACF — causa raiz e plano de ação', 'components' => [
                        F::panel('RACF', $copied(F::text('numeroRacf', 'Nº RACF'), $descricaoProblema)),
                        F::textarea('causaRaiz', 'Identificação da causa raiz', ['required' => true]),
                        $acoes,
                    ]],
                ],
                [
                    'key' => 'a31_valida_racf', 'name' => '31/32 — GEF valida a análise', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 3],
                    'form' => ['title' => 'Validação da análise do RACF', 'components' => [
                        F::panel('Análise do fornecedor', $copied(F::textarea('causaRaiz', 'Causa raiz'), $acoes)),
                        F::yesNo('validaAnalise', 'Valida a análise do RACF?', ['required' => true]),
                        F::textarea('motivoReprovacao', 'Motivo da reprovação', ['required' => true, 'when' => ['validaAnalise' => 'nao']]),
                    ]],
                ],
                ['key' => 'g31_valida', 'name' => 'Análise validada?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'GEF'],
                [
                    'key' => 'a32_revisa_racf', 'name' => '32 — GEF revisa o RACF', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 2],
                    'description' => 'A análise foi reprovada. Ajuste o RACF; a versão anterior permanece no histórico.',
                    'form' => ['title' => 'Revisão do RACF', 'components' => [
                        F::copied(F::text('numeroRacf', 'Nº RACF')),
                        F::copied(F::textarea('motivoReprovacao', 'Motivo da reprovação')),
                        F::columns([[$prazoResposta], [$decisao]]),
                        $responsavelLib,
                        $descricaoProblema,
                        $observacoesRacf,
                    ]],
                ],
                [
                    'key' => 'a301_executa_plano', 'name' => '30.1 — Executar plano de ação', 'type' => S::USER_TASK, 'lane' => 'FORNECEDOR',
                    'config' => ['slaDays' => 30, 'actionPlan' => ['mode' => 'execute', 'field' => 'acoes'], 'notifyOnComplete' => ['GEF']],
                    'description' => 'Conclua cada ação do plano individualmente, anexando evidências. A tarefa só pode ser finalizada quando todas estiverem concluídas.',
                    'form' => ['title' => 'Execução do plano de ação', 'components' => [
                        F::yesNo('necessitaPreventiva', 'Necessita ação preventiva?', ['required' => true]),
                        F::panel('Ação preventiva', [
                            F::textarea('preventivaOque', 'O quê', ['required' => true, 'when' => ['necessitaPreventiva' => 'sim']]),
                            F::columns([[F::text('preventivaQuem', 'Quem', ['required' => true, 'when' => ['necessitaPreventiva' => 'sim']])], [F::date('preventivaQuando', 'Quando', ['required' => true, 'when' => ['necessitaPreventiva' => 'sim']])]]),
                            F::text('preventivaOnde', 'Onde', ['required' => true, 'when' => ['necessitaPreventiva' => 'sim']]),
                            F::textarea('preventivaObs', 'Observação', ['when' => ['necessitaPreventiva' => 'sim']]),
                        ], ['when' => ['necessitaPreventiva' => 'sim'], 'key' => 'panel_preventiva']),
                        F::textarea('justificativaSemPreventiva', 'Justificativa (sem ação preventiva)', ['required' => true, 'when' => ['necessitaPreventiva' => 'nao']]),
                    ]],
                ],
                [
                    'key' => 'a302_eficacia', 'name' => '30.2 — Verificar eficácia do plano', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 30],
                    'form' => ['title' => 'Verificação de eficácia', 'components' => [
                        F::yesNo('planoEficaz', 'O plano de ação foi eficaz?', ['required' => true]),
                        F::textarea('comentarioEficacia', 'Comentários'),
                    ]],
                ],
                ['key' => 'g302_eficaz', 'name' => 'Plano eficaz?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'GEF'],
                [
                    'key' => 'a303_monitora', 'name' => '30.3 — Monitorar fornecimentos', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['slaDays' => 5],
                    'description' => 'Registre a avaliação deste ciclo. O monitoramento é encerrado quando os fornecimentos estiverem conformes ou após 6 ciclos (limite provisório).',
                    'form' => ['title' => 'Monitoramento de fornecimentos', 'components' => [
                        F::auto('ciclosMonitoramento', 'Ciclo de monitoramento', 'counter:MONITORAMENTO'),
                        F::yesNo('fornecimentosConformes', 'Fornecimentos conformes?', ['required' => true]),
                        F::textarea('registroMonitoramento', 'Registro da avaliação', ['required' => true]),
                    ]],
                ],
                ['key' => 'g303_monitora', 'name' => 'Encerrar monitoramento?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'GEF'],
                ['key' => 't303_aguarda', 'name' => 'Aguardar próximo ciclo (30 dias)', 'type' => S::TIMER, 'lane' => 'GEF', 'config' => ['mode' => 'duration', 'duration' => 'P30D']],
                ['key' => 'm_trilha_a', 'name' => 'Causa raiz concluída', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'GEF'],
                // Trilha B — destino do material
                [
                    'key' => 'a33_garantia_devolucao', 'name' => '33–35 — Garantia ou devolução', 'type' => S::USER_TASK, 'lane' => 'FORNECEDOR', 'config' => ['slaDays' => 5],
                    'form' => ['title' => 'Garantia ou devolução', 'components' => [
                        F::radio('tratativa', 'Tratativa do material', ['garantia' => 'Garantia', 'devolucao' => 'Devolução'], ['required' => true]),
                        F::radio('quemEnviaPrimeiro', 'Garantia: quem envia primeiro o material NC? (35)', ['tmsa' => 'TMSA envia primeiro', 'fornecedor' => 'Fornecedor envia primeiro'], ['required' => true, 'when' => ['tratativa' => 'garantia']]),
                        F::text('nfFornecedor', 'NF do fornecedor', ['required' => true, 'when' => ['tratativa' => 'garantia', 'quemEnviaPrimeiro' => 'fornecedor']]),
                        F::file('documentoNfFornecedor', 'Documento da NF', ['required' => true, 'when' => ['tratativa' => 'garantia', 'quemEnviaPrimeiro' => 'fornecedor']]),
                    ]],
                ],
                ['key' => 'g33_tratativa', 'name' => 'Destino do material', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'FORNECEDOR'],
                [
                    'key' => 'a37_ppcpm_senf', 'name' => '37 — PPCPM abre SENF', 'type' => S::USER_TASK, 'lane' => 'PPCPM', 'config' => ['slaDays' => 2],
                    'form' => ['title' => 'Abertura de SENF — PPCPM', 'components' => [F::text('numeroSenf', 'Nº SENF', ['required' => true])]],
                ],
                [
                    'key' => 'a38_sup_senf', 'name' => '38 — Suprimentos abre SENF', 'type' => S::USER_TASK, 'lane' => 'SUP', 'config' => ['slaDays' => 2],
                    'form' => ['title' => 'Abertura de SENF — Suprimentos', 'components' => [F::text('numeroSenf', 'Nº SENF', ['required' => true])]],
                ],
                [
                    'key' => 'a39_fiscal_ocdev', 'name' => '39 — Fiscal gera OCDev no AX', 'type' => S::USER_TASK, 'lane' => 'FISCAL', 'config' => ['slaDays' => 2],
                    'form' => ['title' => 'OCDev — Fiscal', 'components' => [
                        F::copied(F::text('nfFornecedor', 'NF do fornecedor')),
                        F::text('numeroOcdev', 'Nº OCDev', ['required' => true]),
                    ]],
                ],
                [
                    'key' => 'a45_sup_ocdev', 'name' => '45/46 — Suprimentos abre OCDev', 'type' => S::USER_TASK, 'lane' => 'SUP', 'config' => ['slaDays' => 2],
                    'form' => ['title' => 'OCDev — Suprimentos', 'components' => [
                        F::text('numeroOcdev', 'Nº OCDev', ['required' => true]),
                        F::file('pdfOcdev', 'PDF da OCDev', ['required' => true]),
                        F::yesNo('necessitaNfEspelho', 'Necessita NF espelho? (46)', ['required' => true]),
                    ]],
                ],
                ['key' => 'g46_nf_espelho', 'name' => 'NF espelho?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'SUP'],
                [
                    'key' => 'a47_nf_espelho', 'name' => '47 — Faturamento emite NF espelho', 'type' => S::USER_TASK, 'lane' => 'FATURAMENTO',
                    'config' => ['slaDays' => 2, 'notifyOnComplete' => ['FORNECEDOR']],
                    'form' => ['title' => 'NF espelho', 'components' => [
                        F::text('numeroNfEspelho', 'Nº NF espelho', ['required' => true]),
                        F::file('documentoNfEspelho', 'Documento da NF espelho', ['required' => true]),
                    ]],
                ],
                ['key' => 'm_qualidade', 'name' => 'Preparar separação', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'QUALIDADE'],
                ['key' => 's40_ax', 'name' => 'AX: diário, marcação OCDev e bloqueio', 'type' => S::SERVICE_TASK, 'lane' => 'QUALIDADE', 'config' => ['operation' => 'ax.transferAndBlock']],
                [
                    'key' => 'a40_separacao', 'name' => '40 — Separação, entrega e transferência', 'type' => S::USER_TASK, 'lane' => 'QUALIDADE', 'config' => ['slaDays' => 2],
                    'description' => 'O diário de transferência, a marcação da OCDev e o bloqueio do item são automáticos no AX; confirme apenas a separação física.',
                    'form' => ['title' => 'Separação, entrega e transferência', 'components' => [
                        F::columns([[F::auto('entreguePor', 'Entregue por', 'user.name')], [F::copied(F::text('numeroOcdev', 'Nº OCDev'))]]),
                        F::text('numeroDiarioTransferencia', 'Nº do diário de transferência (AX)', ['disabled' => true]),
                        F::checkbox('ocdevMarcadaNaOc', 'OCDev marcada na OC de origem (AX)', ['disabled' => true]),
                        F::checkbox('itemBloqueado', 'Item bloqueado para outras necessidades (AX)', ['disabled' => true]),
                        F::checkbox('confirmacaoSeparacao', 'Confirmo a separação e a entrega física do material', ['required' => true]),
                    ]],
                ],
                [
                    'key' => 'a41_frete', 'name' => '41–43 — Expedição define o frete', 'type' => S::USER_TASK, 'lane' => 'EXPEDICAO', 'config' => ['slaDays' => 2],
                    'form' => ['title' => 'Definição do frete', 'components' => [
                        F::radio('tipoFrete', 'Frete', ['cif' => 'CIF — TMSA', 'fob' => 'FOB — Fornecedor'], ['required' => true]),
                        F::columns([
                            [F::date('dataColeta', 'Data de coleta', ['required' => true, 'time' => true, 'when' => ['tipoFrete' => 'cif']])],
                            [F::number('peso', 'Peso (kg)', ['required' => true, 'when' => ['tipoFrete' => 'cif']])],
                            [F::number('volume', 'Volume', ['required' => true, 'when' => ['tipoFrete' => 'cif']])],
                        ]),
                        F::date('dataColetaFob', 'Data de coleta (FOB)', ['required' => true, 'time' => true, 'when' => ['tipoFrete' => 'fob'], 'description' => 'O processo aguarda esta data antes do faturamento.']),
                        F::checkbox('solicitaEmissaoNf', 'Solicitar emissão da NF ao Faturamento (43)', ['required' => true]),
                    ]],
                ],
                ['key' => 'g41_frete', 'name' => 'Tipo de frete', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'EXPEDICAO'],
                ['key' => 't42_fob', 'name' => 'Aguardar data de coleta FOB', 'type' => S::TIMER, 'lane' => 'EXPEDICAO', 'config' => ['mode' => 'date', 'dateField' => 'dataColetaFob']],
                [
                    'key' => 'a44_faturamento', 'name' => '44 — Faturamento emite a NF', 'type' => S::USER_TASK, 'lane' => 'FATURAMENTO',
                    'config' => ['slaDays' => 2, 'notifyOnComplete' => ['PPCPM', 'EXPEDICAO']],
                    'form' => ['title' => 'Emissão da NF', 'components' => [F::text('numeroNfFaturamento', 'Nº NF', ['required' => true])]],
                ],
                ['key' => 'm_trilha_b', 'name' => 'Destino do material concluído', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'FATURAMENTO'],
                ['key' => 'p_join', 'name' => 'Sincronizar trilhas', 'type' => S::PARALLEL_GATEWAY, 'lane' => 'GEF'],
                ['key' => 'end_ok', 'name' => 'Concluído', 'type' => S::END, 'lane' => 'GEF', 'config' => ['result' => 'concluido', 'notify' => ['CQ']]],
            ],
            'transitions' => [
                ['inicio', 'a01_abertura'],
                ['a01_abertura', 'a05_analise_previa'],
                ['a05_analise_previa', 'g06_urgente'],
                ['g06_urgente', 'a09_comite_urgencia', ['when' => B::when(['urgente' => 'sim']), 'label' => 'Urgente']],
                ['g06_urgente', 'a11_gerar_racf', ['default' => true, 'label' => 'Não urgente']],
                ['a09_comite_urgencia', 'a11_gerar_racf'],
                ['a11_gerar_racf', 'a24_analise_fornecedor'],
                ['a24_analise_fornecedor', 'g24_procede'],
                ['g24_procede', 'a26_gef_np', ['when' => B::when(['procede' => 'nao']), 'label' => 'Não procede']],
                ['g24_procede', 'p_fork', ['default' => true, 'label' => 'Procede']],
                ['a26_gef_np', 'g26_concorda'],
                ['g26_concorda', 'end_np', ['when' => B::when(['gefConcordaNp' => 'sim']), 'label' => 'Concorda']],
                ['g26_concorda', 'a10_comite_np', ['when' => B::when(['gefConcordaNp' => 'nao', 'necessitaComite' => 'sim']), 'label' => 'Comitê']],
                ['g26_concorda', 'a11_gerar_racf', ['default' => true, 'label' => 'Revisar RACF']],
                ['a10_comite_np', 'a11_gerar_racf'],
                ['p_fork', 'a30_analise_racf'],
                ['p_fork', 'a33_garantia_devolucao'],
                ['a30_analise_racf', 'a31_valida_racf'],
                ['a31_valida_racf', 'g31_valida'],
                ['g31_valida', 'a301_executa_plano', ['when' => B::when(['validaAnalise' => 'sim']), 'label' => 'Validada']],
                ['g31_valida', 'a32_revisa_racf', ['default' => true, 'label' => 'Reprovada']],
                ['a32_revisa_racf', 'a30_analise_racf'],
                ['a301_executa_plano', 'a302_eficacia'],
                ['a302_eficacia', 'g302_eficaz'],
                ['g302_eficaz', 'm_trilha_a', ['when' => B::when(['planoEficaz' => 'sim']), 'label' => 'Eficaz']],
                ['g302_eficaz', 'a303_monitora', ['default' => true, 'label' => 'Não eficaz']],
                ['a303_monitora', 'g303_monitora'],
                ['g303_monitora', 'm_trilha_a', ['when' => ['match' => 'any', 'rules' => [
                    ['field' => 'fornecimentosConformes', 'operator' => 'eq', 'value' => 'sim'],
                    ['field' => 'ciclosMonitoramento', 'operator' => 'gte', 'value' => 6],
                ]], 'label' => 'Conforme ou limite de ciclos']],
                ['g303_monitora', 't303_aguarda', ['default' => true, 'label' => 'Continuar monitorando']],
                ['t303_aguarda', 'a303_monitora'],
                ['m_trilha_a', 'p_join'],
                ['a33_garantia_devolucao', 'g33_tratativa'],
                ['g33_tratativa', 'a45_sup_ocdev', ['when' => B::when(['tratativa' => 'devolucao']), 'label' => 'Devolução']],
                ['g33_tratativa', 'a39_fiscal_ocdev', ['when' => B::when(['tratativa' => 'garantia', 'quemEnviaPrimeiro' => 'fornecedor']), 'label' => 'Garantia — fornecedor envia']],
                ['g33_tratativa', 'a38_sup_senf', ['when' => B::when(['tratativa' => 'garantia', 'quemEnviaPrimeiro' => 'tmsa', 'tipoItem' => '01']), 'label' => 'Garantia TMSA — tipo 01']],
                ['g33_tratativa', 'a37_ppcpm_senf', ['default' => true, 'label' => 'Garantia TMSA — tipos 03/09']],
                ['a37_ppcpm_senf', 'm_trilha_b'],
                ['a38_sup_senf', 'm_trilha_b'],
                ['a39_fiscal_ocdev', 'm_qualidade'],
                ['a45_sup_ocdev', 'g46_nf_espelho'],
                ['g46_nf_espelho', 'a47_nf_espelho', ['when' => B::when(['necessitaNfEspelho' => 'sim']), 'label' => 'Sim']],
                ['g46_nf_espelho', 'm_qualidade', ['default' => true, 'label' => 'Não']],
                ['a47_nf_espelho', 'm_trilha_b'],
                ['m_qualidade', 's40_ax'],
                ['s40_ax', 'a40_separacao'],
                ['a40_separacao', 'a41_frete'],
                ['a41_frete', 'g41_frete'],
                ['g41_frete', 't42_fob', ['when' => B::when(['tipoFrete' => 'fob']), 'label' => 'FOB']],
                ['g41_frete', 'a44_faturamento', ['default' => true, 'label' => 'CIF']],
                ['t42_fob', 'a44_faturamento'],
                ['a44_faturamento', 'm_trilha_b'],
                ['m_trilha_b', 'p_join'],
                ['p_join', 'end_ok'],
            ],
        ];
    }
}
