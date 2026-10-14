<?php

namespace Database\Seeders\Blueprints;

use App\Flow\FlowBlueprint as B;
use App\Forms\FormioBuilder as F;
use App\Models\ProcessStageVersion as S;

/** Fluxos de demonstração do protótipo (catálogo com áreas Compras e Operações). */
final class DemoFlows
{
    public static function homologacaoFornecedor(): array
    {
        return [
            'key' => 'homologacao_fornecedor',
            'name' => 'Homologação de fornecedor',
            'category' => 'Compras',
            'prefix' => 'HF',
            'description' => 'Avaliação documental e aprovação de novo fornecedor.',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START, 'lane' => 'SUP'],
                [
                    'key' => 'solicitar', 'name' => 'Solicitar homologação', 'type' => S::USER_TASK, 'lane' => 'SUP',
                    'form' => ['title' => 'Cadastro inicial do fornecedor', 'components' => [
                        F::text('razaoSocial', 'Razão social', ['required' => true]),
                        F::text('cnpj', 'CNPJ', ['required' => true, 'placeholder' => '00.000.000/0000-00']),
                        F::select('categoriaFornecimento', 'Categoria de fornecimento', ['mp' => 'Matéria-prima', 'componente' => 'Componente', 'servico' => 'Serviço'], ['required' => true]),
                        F::file('documentosFornecedor', 'Documentos (contrato social, certidões)'),
                    ]],
                ],
                [
                    'key' => 'avaliar', 'name' => 'Avaliar documentação', 'type' => S::USER_TASK, 'lane' => 'QUALIDADE', 'config' => ['slaDays' => 5],
                    'form' => ['title' => 'Avaliação documental', 'components' => [
                        F::panel('Fornecedor', array_map([F::class, 'copied'], [F::text('razaoSocial', 'Razão social'), F::text('cnpj', 'CNPJ')])),
                        F::yesNo('aprovado', 'Fornecedor aprovado?', ['required' => true]),
                        F::textarea('parecer', 'Parecer', ['required' => true]),
                    ]],
                ],
                ['key' => 'g_aprovado', 'name' => 'Aprovado?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'QUALIDADE'],
                ['key' => 'fim_homologado', 'name' => 'Homologado', 'type' => S::END, 'lane' => 'QUALIDADE', 'config' => ['result' => 'concluido', 'notify' => ['SUP']]],
                ['key' => 'fim_reprovado', 'name' => 'Não homologado', 'type' => S::END, 'lane' => 'QUALIDADE', 'config' => ['result' => 'encerrado_sem_acao', 'notify' => ['SUP']]],
            ],
            'transitions' => [
                ['inicio', 'solicitar'],
                ['solicitar', 'avaliar'],
                ['avaliar', 'g_aprovado'],
                ['g_aprovado', 'fim_homologado', ['when' => B::when(['aprovado' => 'sim']), 'label' => 'Sim']],
                ['g_aprovado', 'fim_reprovado', ['default' => true, 'label' => 'Não']],
            ],
        ];
    }

    public static function freteEspecial(): array
    {
        return [
            'key' => 'aprovacao_frete_especial',
            'name' => 'Aprovação de frete especial',
            'category' => 'Operações',
            'prefix' => 'AFE',
            'description' => 'Análise e aprovação de solicitação de frete fora da política.',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START, 'lane' => 'EXPEDICAO'],
                [
                    'key' => 'registrar', 'name' => 'Registrar solicitação', 'type' => S::USER_TASK, 'lane' => 'EXPEDICAO',
                    'form' => ['title' => 'Dados do frete', 'components' => [
                        F::columns([[F::text('origem', 'Origem', ['required' => true])], [F::text('destino', 'Destino', ['required' => true])]]),
                        F::number('valorEstimado', 'Valor estimado (R$)', ['required' => true]),
                        F::textarea('justificativaFrete', 'Justificativa', ['required' => true]),
                    ]],
                ],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END, 'lane' => 'EXPEDICAO'],
            ],
            'transitions' => [['inicio', 'registrar'], ['registrar', 'fim']],
        ];
    }
}
